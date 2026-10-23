<?php
declare(strict_types=1);
namespace Shiji\Nextcloud;

use Shiji\{HttpError, Notes};
use function Shiji\{query, db, stringInput, config};

final class Conflict extends \RuntimeException
{
    public function __construct(public readonly array $note) { parent::__construct('Note has changed'); }
}

final class NotesApi
{
    public static function settings(array $data = []): array
    {
        foreach (['notesPath', 'fileSuffix'] as $key) {
            if (array_key_exists($key, $data)) stringInput($data, $key, 255);
        }
        // Notes live in the database, not a configurable Nextcloud file tree.
        return ['notesPath' => 'Notes', 'fileSuffix' => '.md'];
    }

    private const SELECT = 'SELECT m.*, g.name AS category, x.title, x.favorite, x.modified,
        EXISTS(SELECT 1 FROM note_attachments a WHERE a.message_id = m.id) AS has_files
        FROM note_messages m JOIN note_groups g ON g.id = m.group_id
        LEFT JOIN nextcloud_note_meta x ON x.message_id = m.id';

    public static function format(array $row): array
    {
        $note = ['id' => (int) $row['id'], 'title' => $row['title'] ?? mb_substr(explode("\n", $row['content'])[0] ?: 'New note', 0, 255),
            'content' => $row['content'], 'category' => $row['category'], 'favorite' => (bool) ($row['favorite'] ?? false),
            'modified' => isset($row['modified']) ? (int) $row['modified'] : strtotime($row['created_at'] . ' UTC'),
            'readonly' => (bool) $row['has_files']];
        $note['etag'] = hash('sha256', json_encode($note, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return $note;
    }

    public static function get(int $id): array
    {
        $row = query(self::SELECT . ' WHERE m.id = ?', [$id])->fetch();
        if (!$row) throw new HttpError(404, 'Note not found');
        return self::format($row);
    }

    public static function listing(?string $category): array
    {
        // Shared sync lock gives one consistent list, including concurrent deletions.
        db()->beginTransaction();
        try {
            query('SELECT current_seq FROM sync_state WHERE id = 1 FOR SHARE');
            $rows = query(self::SELECT . ($category === null ? '' : ' WHERE g.name = ?') . ' ORDER BY m.id', $category === null ? [] : [$category])->fetchAll();
            $notes = array_map(self::format(...), $rows);
            db()->commit();
            return $notes;
        } catch (\Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
    }

    private static function category(string $name): int
    {
        // Empty category has a stable ordinary group; no separate category database.
        $name = $name === '' ? '未分类' : $name;
        $rows = query('SELECT id FROM note_groups WHERE name = ? ORDER BY id', [$name])->fetchAll();
        if (count($rows) > 1) throw new HttpError(409, 'Multiple groups have this name; rename duplicate groups first.');
        if ($rows) return (int) $rows[0]['id'];
        query('INSERT INTO note_groups (name) VALUES (?)', [$name]);
        $id = (int) db()->lastInsertId();
        Notes::event('group_updated', $id);
        return $id;
    }

    public static function save(?int $id, array $data, ?string $match = null): array
    {
        return Notes::transaction(function () use ($id, $data, $match) {
            $old = $id === null ? null : self::get($id);
            if ($old && $match !== null && $match !== '*' && trim($match, '"') !== $old['etag']) throw new Conflict($old);
            if ($old && $old['readonly']) throw new HttpError(403, 'Notes with attachments are read-only.');
            $text = array_key_exists('content', $data) ? stringInput($data, 'content', config('max_text_length'), false) : ($old['content'] ?? '');
            $title = array_key_exists('title', $data) ? stringInput($data, 'title', 255) : ($old['title'] ?? mb_substr(explode("\n", $text)[0] ?: 'New note', 0, 255));
            $category = array_key_exists('category', $data) ? stringInput($data, 'category', 120) : ($old['category'] ?? '');
            if (array_key_exists('favorite', $data) && !is_bool($data['favorite'])) throw new HttpError(400, 'favorite must be boolean', 'nc_invalid_favorite_type');
            if (array_key_exists('modified', $data) && (!is_int($data['modified']) || $data['modified'] < 0 || $data['modified'] > 253402300799)) throw new HttpError(400, 'Invalid modified timestamp', 'nc_invalid_modified_type');
            $favorite = $data['favorite'] ?? $old['favorite'] ?? false;
            $modified = $data['modified'] ?? (array_key_exists('content', $data) || !$old ? time() : $old['modified']);
            $previous = $id === null ? null : (int) query('SELECT group_id FROM note_messages WHERE id = ?', [$id])->fetchColumn();
            $group = self::category($category);
            if ($id === null) {
                query("INSERT INTO note_messages (group_id,content,source_type) VALUES (?,?,'admin')", [$group, $text]);
                $id = (int) db()->lastInsertId();
            } else {
                query('UPDATE note_messages SET group_id = ?, content = ? WHERE id = ?', [$group, $text, $id]);
            }
            query('INSERT INTO nextcloud_note_meta (message_id,title,favorite,modified) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE title = ?, favorite = ?, modified = ?', [$id, $title, (int) $favorite, $modified, $title, (int) $favorite, $modified]);
            if ($previous !== null && $previous !== $group) Notes::event('message_deleted', $previous, $id);
            // Existing web clients already understand add/upsert events for edits.
            Notes::event('message_added', $group, $id);
            query('UPDATE note_groups SET updated_at = UTC_TIMESTAMP(6) WHERE id IN (?,?)', [$group, $previous ?? $group]);
            return self::get($id);
        });
    }

    public static function delete(int $id): void
    {
        Notes::transaction(function () use ($id) {
            if (self::get($id)['readonly']) throw new HttpError(403, 'Notes with attachments are read-only.');
            $group = (int) query('SELECT group_id FROM note_messages WHERE id = ?', [$id])->fetchColumn();
            query('DELETE FROM note_messages WHERE id = ?', [$id]);
            Notes::event('message_deleted', $group, $id);
        });
    }
}
