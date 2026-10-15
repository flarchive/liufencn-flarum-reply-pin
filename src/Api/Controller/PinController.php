<?php

namespace Cathi\ReplyPin\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Post\Post;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/reply-pin/pin  { postId }
 *
 * 把指定楼层置顶。只有 type='comment' 的回复可置顶，且操作者需持有 pinReply 权限。
 * 已置顶的楼层再次调用为幂等（直接返回当前状态）。
 */
class PinController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);

        if ($actor->isGuest()) {
            return $this->fail('请先登录后再操作', 403);
        }

        if (! $actor->can('pinReply')) {
            return $this->fail('你没有楼层置顶权限，请联系管理员开放', 403);
        }

        $body = (array) $request->getParsedBody();
        $postId = (int) ($body['postId'] ?? 0);

        if ($postId <= 0) {
            return $this->fail('缺少有效的 postId', 422);
        }

        $post = Post::where('id', $postId)->first();

        if (! $post) {
            return $this->fail('该楼层不存在或已被删除', 404);
        }

        if ($post->type !== 'comment') {
            return $this->fail('只能置顶普通回复楼层', 422);
        }

        if ($post->isHidden()) {
            return $this->fail('该楼层已被隐藏，无法置顶', 422);
        }

        if (! $post->is_pinned) {
            $post->is_pinned = true;
            $post->pinned_at = new \DateTime();
            $post->save();
        }

        return new JsonResponse([
            'data' => [
                'id'         => (int) $post->id,
                'isPinned'   => true,
                'pinnedAt'   => $post->pinned_at?->format('c'),
                'discussionId' => (int) $post->discussion_id,
            ],
        ], 200);
    }

    protected function fail(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['errors' => [['status' => (string) $status, 'detail' => $message]]], $status);
    }
}