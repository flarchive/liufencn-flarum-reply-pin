<?php

namespace Cathi\ReplyPin\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Post\Post;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/reply-pin/unpin  { postId }
 *
 * 取消楼层置顶。与 PinController 对称：需 pinReply 权限；幂等。
 */
class UnpinController implements RequestHandlerInterface
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

        if ($post->is_pinned) {
            $post->is_pinned = false;
            $post->pinned_at = null;
            $post->save();
        }

        return new JsonResponse([
            'data' => [
                'id'           => (int) $post->id,
                'isPinned'     => false,
                'pinnedAt'     => null,
                'discussionId' => (int) $post->discussion_id,
            ],
        ], 200);
    }

    protected function fail(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['errors' => [['status' => (string) $status, 'detail' => $message]]], $status);
    }
}