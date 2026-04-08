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
        if ($token === '' || $pageUrl === '') {
            return Response::json(['error' => 'validation'], 422);
        }
        $st = $this->db->pdo()->prepare(
            'SELECT id FROM widget_applications WHERE widget_token = ? AND status = ? LIMIT 1',
        );
        $st->execute([$token, 'approved']);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $appId = (int) $row['id'];
        $ins = $this->db->pdo()->prepare(
            'INSERT INTO widget_views (application_id, page_url) VALUES (?,?)',
        );
        $ins->execute([$appId, mb_substr($pageUrl, 0, 2000)]);

        return Response::json(['ok' => true]);
    }
}
