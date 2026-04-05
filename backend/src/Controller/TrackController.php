<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Http\Request;
use App\Http\Response;
use PDO;

final class TrackController
{
    public function __construct(
        private readonly Database $db,
    ) {
    }

    public function track(Request $request): Response
    {
        $b = $request->body;
        if (!is_array($b)) {
            return Response::json(['error' => 'invalid_json'], 400);
        }
        $token = trim((string) ($b['token'] ?? ''));
        $pageUrl = trim((string) ($b['page_url'] ?? ''));
        $appIdBody = isset($b['application_id']) ? (int) $b['application_id'] : 0;
        if ($token === '' || $pageUrl === '' || $appIdBody < 1) {
            return Response::json(['error' => 'validation'], 422);
        }
        $st = $this->db->pdo()->prepare(
            'SELECT id FROM widget_applications WHERE id = ? AND widget_token = ? AND status = ? LIMIT 1',
        );
        $st->execute([$appIdBody, $token, 'approved']);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $ins = $this->db->pdo()->prepare(
            'INSERT INTO widget_views (application_id, page_url) VALUES (?,?)',
        );
        $ins->execute([(int) $row['id'], mb_substr($pageUrl, 0, 2000)]);
        return Response::json(['ok' => true]);
    }
}
