<?php

namespace Cathi\ReplyPin\Api\Resource;

use Flarum\Api\Schema;
use Flarum\Discussion\Discussion;

/**
 * 向 Discussion 资源注入 pinnedPosts：本次返回的楼层里，哪些是被置顶的。
 *
 * 做法：不额外发请求，直接从 discussion 的 posts relationship 里筛出 is_pinned
 * 的条目，输出成 [postId => true] 的映射，前端用它决定在流顶部渲染哪些楼层。
 *
 * 这样避免了「讨论页 + 置顶列表」两次请求，也避免自建 PinnedReplies 组件。
 */
class DiscussionPinnedFields
{
    public function __invoke(): array
    {
        return [
            Schema\Arr::make('pinnedPostIds')
                ->get(function (Discussion $discussion) {
                    $ids = [];

                    // Flarum 2.0：已加载的 posts 通过 relationship 取
                    $posts = $discussion->getRelation('posts');

                    foreach ($posts as $post) {
                        if (! empty($post->is_pinned)) {
                            $ids[] = (int) $post->id;
                        }
                    }

                    return $ids;
                }),
        ];
    }
}