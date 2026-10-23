<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/NextcloudAPI/NotesApi.php';
use Shiji\Nextcloud\NotesApi;
use Shiji\Auth;
use function Shiji\{query, db};
if (!str_ends_with((string) query('SELECT DATABASE()')->fetchColumn(), '_test')) exit('Dedicated *_test database required.');
function verify(bool $ok, string $name): void {
    if (!$ok) throw new RuntimeException($name);
    echo "PASS: $name\n";
}
$group = Shiji\Notes::createGroup(['name' => 'nc_' . bin2hex(random_bytes(5))]);
verify(NotesApi::settings() === ['notesPath' => 'Notes', 'fileSuffix' => '.md'], 'settings returns compatible object');
verify(NotesApi::settings(['notesPath' => '../../private', 'fileSuffix' => '.php']) === ['notesPath' => 'Notes', 'fileSuffix' => '.md'], 'virtual storage settings remain fixed');
$note = null;
try {
    $note = NotesApi::save(null, ['category' => $group['name'], 'content' => 'hello', 'title' => 'Title', 'favorite' => true]);
    verify($note['category'] === $group['name'] && $note['favorite'], 'existing group and metadata mapping');
    verify(count(NotesApi::listing($group['name'])) === 1, 'category filter lists shared notes');
    $updated = NotesApi::save($note['id'], ['content' => 'updated'], $note['etag']);
    verify($updated['etag'] !== $note['etag'] && $updated['title'] === 'Title', 'partial update changes ETag and preserves title');
    try { NotesApi::save($note['id'], ['content' => 'stale'], $note['etag']); throw new RuntimeException('Conflict accepted'); }
    catch (Shiji\Nextcloud\Conflict $e) { verify($e->note['content'] === 'updated', 'conflict returns current note'); }
    Shiji\Notes::rename((int) $group['id'], ['name' => $group['name'] . '_renamed']);
    verify(NotesApi::get($note['id'])['etag'] !== $updated['etag'], 'group rename changes note ETag');
    $page = Shiji\Notes::page((int) $group['id']);
    verify($page['messages'][0]['content'] === 'updated', 'web message uses same content');
    $empty = NotesApi::save(null, ['category' => $group['name'] . '_renamed']);
    NotesApi::delete($empty['id']);
    verify($empty['content'] === '', 'empty client notes supported');
    verify(!query('SELECT message_id FROM nextcloud_note_meta WHERE message_id = ?', [$empty['id']])->fetchColumn(), 'deleted note metadata is removed');
    $name = 'nc_user_' . bin2hex(random_bytes(4));
    query('INSERT INTO users (username,password_hash) VALUES (?,?)', [$name, password_hash('test-password-long', PASSWORD_DEFAULT)]);
    try {
        verify(Auth::credentials($name, 'test-password-long')['username'] === $name, 'shared password validation');
        try { Auth::credentials($name, 'wrong'); throw new RuntimeException('Bad password accepted'); }
        catch (Shiji\HttpError $e) { verify($e->status === 401, 'bad credentials rejected'); }
    } finally { query('DELETE FROM users WHERE username = ?', [$name]); }
} finally {
    if ($note) NotesApi::delete($note['id']);
    Shiji\Notes::deleteGroup((int) $group['id']);
}
