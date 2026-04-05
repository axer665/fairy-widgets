<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Http\Request;
use App\Http\Response;
use PDO;

final class ModeratorController
{
    public function __construct(
        private readonly Database $db,
    ) {
    }

    public function list(Request $request): Response
    {
        $st = $this->db->pdo()->query(
            'SELECT a.id, a.user_id, a.site_url, a.status, a.widget_token, a.moderator_note, a.created_at, u.login AS user_login
             FROM widget_applications a
             JOIN users u ON u.id = a.user_id
             ORDER BY a.id DESC',
        );
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['user_id'] = (int) $r['user_id'];
        }
        unset($r);
        return Response::json(['applications' => $rows]);
    }

    public function approve(Request $request): Response
    {
        $params = $request->attributes['route_params'] ?? [];
        $id = (int) ($params['id'] ?? 0);
        if ($id < 1) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        $pdo = $this->db->pdo();
        $st = $pdo->prepare('SELECT id, status FROM widget_applications WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return Response::json(['error' => 'not_found'], 404);
        }
        if ($row['status'] !== 'pending') {
            return Response::json(['error' => 'invalid_state', 'message' => 'Только pending'], 409);
        }
        $token = bin2hex(random_bytes(24));
        $up = $pdo->prepare(
            'UPDATE widget_applications SET status = ?, widget_token = ?, moderator_note = NULL WHERE id = ?',
        );
        $up->execute(['approved', $token, $id]);
        return Response::json(['ok' => true, 'id' => $id, 'widget_token' => $token]);
    }

    public function reject(Request $request): Response
    {
        $params = $request->attributes['route_params'] ?? [];
        $id = (int) ($params['id'] ?? 0);
        $b = $request->body;
        $note = is_array($b) ? trim((string) ($b['note'] ?? '')) : '';
        if ($id < 1) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        $pdo = $this->db->pdo();
        $st = $pdo->prepare('SELECT id, status FROM widget_applications WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return Response::json(['error' => 'not_found'], 404);
        }
        if ($row['status'] !== 'pending') {
            return Response::json(['error' => 'invalid_state'], 409);
        }
        $up = $pdo->prepare(
            'UPDATE widget_applications SET status = ?, widget_token = NULL, moderator_note = ? WHERE id = ?',
        );
        $up->execute(['rejected', $note !== '' ? $note : null, $id]);
        return Response::json(['ok' => true, 'id' => $id]);
    }

    public function stats(Request $request): Response
    {
        $params = $request->attributes['route_params'] ?? [];
        $id = (int) ($params['id'] ?? 0);
        if ($id < 1) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        $pdo = $this->db->pdo();
        $st = $pdo->prepare('SELECT id, site_url, status FROM widget_applications WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $app = $st->fetch(PDO::FETCH_ASSOC);
        if (!$app) {
            return Response::json(['error' => 'not_found'], 404);
        }
        $agg = $pdo->prepare(
            'SELECT page_url, COUNT(*) AS cnt FROM widget_views WHERE application_id = ? GROUP BY page_url ORDER BY cnt DESC',
        );
        $agg->execute([$id]);
        $byPage = $agg->fetchAll(PDO::FETCH_ASSOC);
        $total = $pdo->prepare('SELECT COUNT(*) AS c FROM widget_views WHERE application_id = ?');
        $total->execute([$id]);
        $totalRow = $total->fetch(PDO::FETCH_ASSOC);
        return Response::json([
            'application_id' => $id,
            'site_url' => $app['site_url'],
            'total_views' => (int) ($totalRow['c'] ?? 0),
            'by_page' => $byPage,
        ]);
    }
}
