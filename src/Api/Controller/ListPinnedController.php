<?php

namespace Cathi\ReplyPin\Api\Controller;

use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\UriInterface;

/**
 * GET /api/reply-pin/pinned?discussionId=<id>
 *
 * 返回该讨论内被置顶的楼层（按置顶时间正序：最先置顶的排最前）。
 * 前端在讨论页用它渲染顶部「置顶回复」区；游客也可读取（只读，无需权限）。
 */
class ListPinnedController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // 仅做一次「是否登录」的判定，不强制要求权限：这是公开的只读列表。
        RequestUtil::getActor($request);

        $query = $request->getQueryParams();
        $discussionId = (int) ($query['discussionId'] ?? 0);

        if ($discussionId <= 0) {
            return $this->fail('缺少有效的 discussionId', 422);
        }

        $discussion = Discussion::where('discussions.id', $discussionId)->first();

        if (! $discussion) {
            return $this->fail('该讨论不存在', 404);
        }

        $rows = Discussion::where('discussions.id', $discussionId)
            ->join('posts', 'posts.discussion_id', '=', 'discussions.id')
            ->where('posts.type', 'comment')
            ->where('posts.is_pinned', true)
            ->whereNull('posts.hidden_at')
            ->orderBy('posts.pinned_at', 'asc')
            ->orderBy('posts.id', 'asc')
            ->get([
                'posts.id',
                'posts.number',
                'posts.user_id',
                'posts.content',
                'posts.pinned_at',
            ]);

        // ★ 不要用 $discussion->getUrl()：它在 CLI / 无 Request 上下文里不存在
        //   （Model::__call 抛 BadMethodCallException）。这里用当前请求的 origin
        //   + 讨论 slug 自己拼，Flarum 路由是 /d/{slug}/{number}。
        $uri = $request->getUri();
        $base = $uri->getScheme().'://'.$uri->getAuthority();

        // ★ 用 id 形式的 URL（/d/{discussionId}/{number}），不依赖 slug。
            //   discussions.slug 常常是空字符串（Flarum 只在需要标题 slug 时才填），
            //   实测多条讨论 slug 为空，拼出来会变成 /d//2。
            $data = [];

        foreach ($rows as $row) {
            // join() 取出来的是原始值，pinned_at 是字符串而非 DateTime，
            // 不能直接交给 Carbon::instance()（会抛 TypeError）。
            $pinnedAt = null;
            if (! empty($row->pinned_at)) {
                $pinnedAt = \Carbon\Carbon::parse((string) $row->pinned_at)->toIso8601String();
            }

            $data[] = [
                'id'         => (int) $row->id,
                'number'     => (int) $row->number,
                'userId'     => $row->user_id !== null ? (int) $row->user_id : null,
                'excerpt'    => $this->excerpt((string) $row->content),
                'pinnedAt'   => $pinnedAt,
                'url'        => $base.'/d/'.$discussionId.'/'.$row->number,
            ];
        }

        return new JsonResponse(['data' => $data], 200);
    }

    /** 去掉 HTML / 换行，压到 140 字以内做列表摘要 */
    protected function excerpt(string $html): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');

        return mb_strlen($text) > 140 ? mb_substr($text, 0, 140).'…' : $text;
    }

    protected function fail(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['errors' => [['status' => (string) $status, 'detail' => $message]]], $status);
    }
}