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
        $pdo = $this->db->pdo();
        $base = rtrim($this->appUrl, '/');
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['fairies'] = [];
            if ($r['status'] !== 'approved' || empty($r['widget_token'])) {
                unset($r['widget_token']);
                continue;
            }
            $appTok = htmlspecialchars((string) $r['widget_token'], ENT_QUOTES, 'UTF-8');
            unset($r['widget_token']);
            $fst = $pdo->prepare(
                'SELECT id, name, standard_behavior FROM widget_fairies WHERE application_id = ? ORDER BY id ASC',
            );
            $fst->execute([(int) $r['id']]);
            $frows = $fst->fetchAll(PDO::FETCH_ASSOC);
            foreach ($frows as $fr) {
                $fid = (int) $fr['id'];
                $es = $pdo->prepare(
                    'SELECT widget_event_id FROM fairy_events WHERE fairy_id = ? ORDER BY widget_event_id ASC',
                );
                $es->execute([$fid]);
                $assigned = array_map('intval', $es->fetchAll(PDO::FETCH_COLUMN));
                $r['fairies'][] = [
                    'id' => $fid,
                    'name' => $fr['name'],
                    'standard_behavior' => (bool) (int) ($fr['standard_behavior'] ?? 0),
                    'embed_snippet' => '<script src="' . $base . '/widget-loader?token=' . $appTok . '&fairy_id=' . $fid . '"></script>',
                    'assigned_event_ids' => $assigned,
                ];
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
