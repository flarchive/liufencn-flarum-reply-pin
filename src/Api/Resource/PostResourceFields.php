<?php

namespace Cathi\ReplyPin\Api\Resource;

use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Post\Post;

/**
 * 向 Post 资源注入楼层置顶相关字段：
 *   isPinned     该楼层是否被置顶
 *   pinnedAt     置顶时间（ISO8601 或 null）
 *   canPinReply  当前用户是否可以把这条楼层置顶/取消置顶
 *
 * 前端据此：
 *   - 决定是否在 Post 控件菜单里显示「置顶 / 取消置顶」
 *   - 给被置顶的楼层加图钉徽标
 */
class PostResourceFields
{
    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('isPinned')
                ->get(fn (Post $post) => (bool) $post->is_pinned),

            Schema\DateTime::make('pinnedAt')
                ->get(fn (Post $post) => $post->pinned_at),

            Schema\Boolean::make('canPinReply')
                ->get(function (Post $post, Context $context) {
                    $actor = $context->getActor();

                    // 只有普通回复（comment）可置顶；首帖与事件帖不可
                    if ($post->type !== 'comment') {
                        return false;
                    }

                    if ($actor->isGuest()) {
                        return false;
                    }

                    return $actor->can('pinReply');
                }),
        ];
    }
}