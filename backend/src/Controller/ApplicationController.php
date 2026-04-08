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
            'SELECT id, site_url, status, widget_token, moderator_note, standard_behavior, created_at, updated_at
             FROM widget_applications WHERE user_id = ? ORDER BY id DESC',
        );
        $st->execute([$uid]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['standard_behavior'] = (bool) (int) ($r['standard_behavior'] ?? 0);
            $r['embed_snippet'] = null;
            $r['widget_call_hint'] = null;
            if ($r['status'] === 'approved' && !empty($r['widget_token'])) {
                $base = rtrim($this->appUrl, '/');
                $t = htmlspecialchars($r['widget_token'], ENT_QUOTES, 'UTF-8');
                $r['embed_snippet'] = '<script src="' . $base . '/widget-loader?token=' . $t . '"></script>';
                $r['widget_call_hint'] =
                    'После загрузки скрипта: myLittleFairyWidget.show("ключ_события") — ключ задаётся в блоке «События».';
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

    public function update(Request $request): Response
    {
        $params = $request->attributes['route_params'] ?? [];
        $id = (int) ($params['id'] ?? 0);
        if ($id < 1) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        $b = $request->body;
        if (!is_array($b) || !array_key_exists('standard_behavior', $b)) {
            return Response::json(['error' => 'validation'], 422);
        }
        $sb = $b['standard_behavior'];
        $val = is_bool($sb) ? $sb : (bool) (int) $sb;

        $uid = (int) ($request->attributes['user_id'] ?? 0);
        $st = $this->db->pdo()->prepare(
            'UPDATE widget_applications SET standard_behavior = ? WHERE id = ? AND user_id = ?',
        );
        $st->execute([(int) $val, $id, $uid]);
        if ($st->rowCount() === 0) {
            return Response::json(['error' => 'not_found'], 404);
        }

        return Response::json(['ok' => true, 'standard_behavior' => $val]);
    }
}
