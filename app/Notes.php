<?php
declare(strict_types=1);
namespace Shiji;

final class Notes
{
    public static function lock(): int
    {
        // Every mutation locks this row BEFORE touching business rows. Sequence visibility
        // follows commit order, including deletions and concurrent anonymous submissions.
        return (int) query('SELECT current_seq FROM sync_state WHERE id = 1 FOR UPDATE')->fetchColumn();
    }

    public static function event(string $type, ?int $group, ?int $message = null): void
    {
        query('UPDATE sync_state SET current_seq = current_seq + 1 WHERE id = 1');
        $seq = query('SELECT current_seq FROM sync_state WHERE id = 1')->fetchColumn();
        query('INSERT INTO sync_events (seq, event_type, group_id, message_id) VALUES (?, ?, ?, ?)', [$seq, $type, $group, $message]);
    }

    public static function transaction(callable $fn): mixed
    {
        db()->beginTransaction();
        try {
            self::lock();
            $result = $fn();
            db()->commit();
            return $result;
        } catch (\Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            throw $e;
        }
    }

    public static function group(int $id): array
    {
        $row = query('SELECT * FROM note_groups WHERE id = ?', [$id])->fetch();
        if (!$row) throw new HttpError(404, '分组不存在。', 'group_missing');
        return $row;
    }

    public static function groups(): array
    {
        return query('SELECT g.id, g.name, g.group_type, g.updated_at, (SELECT COUNT(*) FROM note_messages WHERE group_id = g.id) AS message_count, (SELECT LEFT(content, 160) FROM note_messages WHERE group_id = g.id ORDER BY created_at DESC, id DESC LIMIT 1) AS summary, (SELECT created_at FROM note_messages WHERE group_id = g.id ORDER BY created_at DESC, id DESC LIMIT 1) AS last_message_at FROM note_groups g ORDER BY COALESCE(last_message_at, g.created_at) DESC, g.id DESC')->fetchAll();
    }

    public static function message(array $row): array
    {
        $row['attachments'] = query('SELECT id, original_name, mime_type, file_size, file_extension FROM note_attachments WHERE message_id = ? ORDER BY id', [$row['id']])->fetchAll();
        return $row;
    }

    public static function page(int $group, ?int $before = null): array
    {
        self::group($group);
        $params = [$group];
        $where = '';
        if ($before) {
            $anchor = query('SELECT created_at, id FROM note_messages WHERE id = ? AND group_id = ?', [$before, $group])->fetch();
            if ($anchor) {
                $where = ' AND (created_at < ? OR (created_at = ? AND id < ?))';
                $params = [$group, $anchor['created_at'], $anchor['created_at'], $anchor['id']];
            } else {
                // IDs are allocated under the same global write lock as timestamps.
                $where = ' AND id < ?';
                $params[] = $before;
            }
        }
        $rows = query('SELECT id, group_id, content, source_type, created_at FROM note_messages WHERE group_id = ?' . $where . ' ORDER BY created_at DESC, id DESC LIMIT 51', $params)->fetchAll();
        $more = count($rows) > 50;
        if ($more) array_pop($rows);
        $rows = array_reverse($rows);
        return ['messages' => array_map(self::message(...), $rows), 'has_more' => $more, 'before' => $rows[0]['id'] ?? null];
    }

    public static function snapshot(int $group): array
    {
        // A short shared lock makes the page and cursor one consistent baseline.
        db()->beginTransaction();
        try {
            $seq = query('SELECT current_seq FROM sync_state WHERE id = 1 FOR SHARE')->fetchColumn();
            $result = ['groups' => self::groups(), 'page' => self::page($group), 'cursor' => (string) $seq];
            db()->commit();
            return $result;
        } catch (\Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            throw $e;
        }
    }

    public static function createGroup(array $data): array
    {
        $name = stringInput($data, 'name', 120);
        if ($name === '') throw new HttpError(422, '分组名称不能为空。');
        return self::transaction(function () use ($name) {
            query('INSERT INTO note_groups (name) VALUES (?)', [$name]);
            $id = (int) db()->lastInsertId();
            self::event('group_updated', $id);
            return self::group($id);
        });
    }

    public static function rename(int $id, array $data): void
    {
        $name = stringInput($data, 'name', 120);
        if ($name === '') throw new HttpError(422, '分组名称不能为空。');
        self::transaction(function () use ($id, $name) {
            $group = self::group($id);
            if ($group['group_type'] !== 'normal') throw new HttpError(403, '系统收件箱不能重命名。');
            query('UPDATE note_groups SET name = ?, updated_at = UTC_TIMESTAMP(6) WHERE id = ?', [$name, $id]);
            self::event('group_updated', $id);
        });
    }

    public static function deleteGroup(int $id): void
    {
        self::transaction(function () use ($id) {
            if (self::group($id)['group_type'] !== 'normal') throw new HttpError(403, '匿名收件箱不能删除。');
            if (query('SELECT id FROM note_messages WHERE group_id = ? LIMIT 1', [$id])->fetchColumn()) {
                throw new HttpError(409, '分组中还有内容，请先删除全部消息和附件后再删除分组。', 'group_not_empty');
            }
            query('DELETE FROM note_groups WHERE id = ?', [$id]);
            self::event('group_deleted', $id);
        });
    }

    public static function send(int $group, array $data, ?string $code = null): array
    {
        $text = stringInput($data + ['content' => ''], 'content', config('max_text_length'), false);
        $files = Uploads::stage();
        try {
            if (trim($text) === '' && !$files) throw new HttpError(422, '请输入内容或添加附件。');
            $message = self::transaction(function () use (&$files, $text, $group, $code) {
                if ($code !== null) {
                    $token = Tokens::validate($code, true);
                    $bytes = array_sum(array_column($files, 'size'));
                    if ($token['submit_count'] >= config('anonymous_max_submissions') || $token['uploaded_bytes'] + $bytes > config('anonymous_max_bytes')) throw new HttpError(429, '此链接的投递次数或附件总量已达上限。');
                    query('UPDATE anonymous_tokens SET submit_count = submit_count + 1, uploaded_bytes = uploaded_bytes + ? WHERE id = ?', [$bytes, $token['id']]);
                }
                self::group($group);
                query('INSERT INTO note_messages (group_id, content, source_type) VALUES (?, ?, ?)', [$group, $text, $code === null ? 'admin' : 'anonymous']);
                $id = (int) db()->lastInsertId();
                Uploads::attach($id, $files);
                // Upload and save both recheck expiry; a long disk operation must not
                // commit an anonymous message after its authorization has elapsed.
                if ($code !== null) Tokens::validate($code);
                query('UPDATE note_groups SET updated_at = UTC_TIMESTAMP(6) WHERE id = ?', [$group]);
                self::event('message_added', $group, $id);
                return self::message(query('SELECT id, group_id, content, source_type, created_at FROM note_messages WHERE id = ?', [$id])->fetch());
            });
            return $message;
        } catch (\Throwable $e) {
            Uploads::discard($files);
            throw $e;
        }
    }

    public static function deleteMessages(int $group, array $ids): void
    {
        if (!$ids || count($ids) > 100 || !array_is_list($ids)) throw new HttpError(422, '一次可删除 1 至 100 条消息。');
        $ids = array_values(array_unique(array_map(id(...), $ids)));
        self::transaction(function () use ($group, $ids) {
            self::group($group);
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $rows = query("SELECT id FROM note_messages WHERE group_id = ? AND id IN ($marks)", [$group, ...$ids])->fetchAll();
            if (count($rows) !== count($ids)) throw new HttpError(409, '部分消息已删除或不属于当前分组，请刷新后重试。');
            query("INSERT IGNORE INTO file_garbage (storage_name) SELECT storage_name FROM note_attachments WHERE message_id IN ($marks)", $ids);
            query("DELETE FROM note_messages WHERE group_id = ? AND id IN ($marks)", [$group, ...$ids]);
            foreach ($ids as $id) self::event('message_deleted', $group, $id);
        });
        Uploads::collect();
    }

    public static function sync(string $since, int $group): array
    {
        if (!preg_match('/^[0-9]{1,18}$/D', $since)) throw new HttpError(400, '同步游标无效。');
        db()->beginTransaction();
        try {
            $state = query('SELECT current_seq, min_cursor FROM sync_state WHERE id = 1 FOR SHARE')->fetch();
            $reload = (int) $since < (int) $state['min_cursor'] || (int) $since > (int) $state['current_seq'];
            $events = $reload ? [] : query('SELECT seq, event_type, group_id, message_id FROM sync_events WHERE seq > ? AND seq <= ? ORDER BY seq LIMIT 201', [$since, $state['current_seq']])->fetchAll();
            if (count($events) > 200) { $events = []; $reload = true; }
            foreach ($events as &$event) {
                if ($event['event_type'] === 'message_added' && (int) $event['group_id'] === $group) {
                    $row = query('SELECT id, group_id, content, source_type, created_at FROM note_messages WHERE id = ?', [$event['message_id']])->fetch();
                    $event['message'] = $row ? self::message($row) : null;
                }
            }
            $result = ['next_cursor' => (string) $state['current_seq'], 'events' => $events, 'reload' => $reload, 'groups' => ($events || $reload) ? self::groups() : null];
            db()->commit();
            return $result;
        } catch (\Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            throw $e;
        }
    }
}
