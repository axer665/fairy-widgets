<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Http\Request;
use App\Http\Response;
use App\Util\HostNormalizer;
use PDO;

final class ApplicationController
{
    public function __construct(
        private readonly Database $db,
        private readonly string $appUrl,
    ) {
    }

    public function list(Request $request): Response
    {
        $uid = (int) ($request->attributes['user_id'] ?? 0);
        $st = $this->db->pdo()->prepare(
            'SELECT id, site_url, status, widget_token, moderator_note, created_at, updated_at
             FROM widget_applications WHERE user_id = ? ORDER BY id DESC',
        );
        $st->execute([$uid]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['embed_snippet'] = null;
            if ($r['status'] === 'approved' && !empty($r['widget_token'])) {
                $base = rtrim($this->appUrl, '/');
                $t = htmlspecialchars($r['widget_token'], ENT_QUOTES, 'UTF-8');
                $r['embed_snippet'] = '<script src="' . $base . '/widget-loader?token=' . $t . '"></script>';
            }
        }
        unset($r);
        return Response::json(['applications' => $rows]);
    }

    public function create(Request $request): Response
    {
        $b = $request->body;
        if (!is_array($b)) {
            return Response::json(['error' => 'invalid_json'], 400);
        }
        $siteUrl = trim((string) ($b['site_url'] ?? ''));
        $host = HostNormalizer::fromUrl($siteUrl);
        if ($host === null) {
            return Response::json(['error' => 'validation', 'message' => 'Некорректный site_url'], 422);
        }
        $uid = (int) ($request->attributes['user_id'] ?? 0);
        $st = $this->db->pdo()->prepare(
            'INSERT INTO widget_applications (user_id, site_url, status) VALUES (?,?,?)',
        );
        $st->execute([$uid, $siteUrl, 'pending']);
        $id = (int) $this->db->pdo()->lastInsertId();
        return Response::json(['id' => $id, 'site_url' => $siteUrl, 'status' => 'pending'], 201);
    }
}
