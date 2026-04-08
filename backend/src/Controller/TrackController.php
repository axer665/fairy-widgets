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
        $fairyId = isset($b['fairy_id']) ? (int) $b['fairy_id'] : 0;
        $appIdBody = isset($b['application_id']) ? (int) $b['application_id'] : 0;
        if ($token === '' || $pageUrl === '' || $fairyId < 1 || $appIdBody < 1) {
            return Response::json(['error' => 'validation'], 422);
        }
        $st = $this->db->pdo()->prepare(
            'SELECT a.id FROM widget_applications a
             INNER JOIN widget_fairies f ON f.application_id = a.id AND f.id = ?
             WHERE a.id = ? AND a.widget_token = ? AND a.status = ? LIMIT 1',
        );
        $st->execute([$fairyId, $appIdBody, $token, 'approved']);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $ins = $this->db->pdo()->prepare(
            'INSERT INTO widget_views (application_id, page_url) VALUES (?,?)',
        );
        $ins->execute([$appIdBody, mb_substr($pageUrl, 0, 2000)]);

        return Response::json(['ok' => true]);
    }
}
