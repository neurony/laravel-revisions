<?php

namespace Zbiller\Revisions\Tests;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Zbiller\Revisions\Models\Revision;
use Zbiller\Revisions\Options\RevisionOptions;
use Zbiller\Revisions\Tests\Models\Comment;
use Zbiller\Revisions\Tests\Models\Post;
use Zbiller\Revisions\Tests\Models\Tag;

class HasRevisionsTest extends TestCase
{
    /** @test */
    public function it_automatically_creates_a_revision_when_the_record_changes()
    {
        $this->makeModels();
        $this->modifyPost();

        $this->assertEquals(1, Revision::count());
    }

    /** @test */
    public function it_can_manually_create_a_revision()
    {
        $this->makeModels();

        $this->post->saveAsRevision();

        $this->assertEquals(1, Revision::count());
    }

    /** @test */
    public function it_stores_the_original_attribute_values_when_creating_a_revision()
    {
        $this->makeModels();
        $this->modifyPost();

        $revision = $this->post->revisions()->first();

        $this->assertEquals('Post name', $revision->metadata['name']);
        $this->assertEquals('post-slug', $revision->metadata['slug']);
        $this->assertEquals('Post content', $revision->metadata['content']);
        $this->assertEquals(10, $revision->metadata['votes']);
        $this->assertEquals(100, $revision->metadata['views']);
    }

    /** @test */
    public function it_can_rollback_to_a_past_revision()
    {
        $this->makeModels();
        $this->modifyPost();

        $this->assertEquals('Another post name', $this->post->name);
        $this->assertEquals('another-post-slug', $this->post->slug);
        $this->assertEquals('Another post content', $this->post->content);
        $this->assertEquals(20, $this->post->votes);
        $this->assertEquals(200, $this->post->views);

        $this->post->rollbackToRevision($this->post->revisions()->first());

        $this->assertEquals('Post name', $this->post->name);
        $this->assertEquals('post-slug', $this->post->slug);
        $this->assertEquals('Post content', $this->post->content);
        $this->assertEquals(10, $this->post->votes);
        $this->assertEquals(100, $this->post->views);
    }

    /** @test */
    public function it_creates_a_new_revision_when_rolling_back_to_a_past_revision()
    {
        $this->makeModels();
        $this->modifyPost();

        $this->post->rollbackToRevision($this->post->revisions()->first());

        $this->assertEquals(2, Revision::count());
    }

    /** @test */
    public function it_can_delete_all_revisions_of_a_record()
    {
        $this->makeModels();
        $this->modifyPost();
        $this->modifyPostAgain();

        $this->assertEquals(2, Revision::count());

        $this->post->deleteAllRevisions();

        $this->assertEquals(0, Revision::count());
    }

    /** @test */
    public function it_can_create_a_revision_when_creating_the_record()
    {
        $model = new class extends Post {
            public function getRevisionOptions() : RevisionOptions
            {
                return parent::getRevisionOptions()->enableRevisionOnCreate();
            }
        };

        $this->makeModels($model);

        $this->assertEquals(1, Revision::count());
    }

    /** @test */
    public function it_can_limit_the_number_of_revisions_a_record_can_have()
    {
        $model = new class extends Post {
            public function getRevisionOptions() : RevisionOptions
            {
                return parent::getRevisionOptions()->limitRevisionsTo(5);
            }
        };

        $this->makeModels($model);

        for ($i = 1; $i <= 10; $i++) {
            $this->modifyPost();
            $this->modifyPostAgain();
        }

        $this->assertEquals(5, Revision::count());
    }

    /** @test */
    public function it_deletes_the_oldest_revisions_when_the_limit_is_achieved()
    {
        $model = new class extends Post {
            public function getRevisionOptions() : RevisionOptions
            {
                return parent::getRevisionOptions()->limitRevisionsTo(5);
            }
        };

        $this->makeModels($model);

        for ($i = 1; $i <= 10; $i++) {
            $this->modifyPost();
            $this->modifyPostAgain();
        }

        $this->assertEquals(16, $this->post->revisions()->oldest()->first()->id);
    }

    /** @test */
    public function it_can_specify_only_certain_fields_when_creating_a_revision()
    {
        $model = new class extends Post {
            public function getRevisionOptions() : RevisionOptions
            {
                return parent::getRevisionOptions()->fieldsToRevision('name', 'votes');
            }
        };

        $this->makeModels($model);
        $this->modifyPost();

        $revision = $this->post->revisions()->first();

        $this->assertArrayHasKey('name', $revision->metadata);
        $this->assertArrayHasKey('votes', $revision->metadata);
        $this->assertArrayNotHasKey('slug', $revision->metadata);
        $this->assertArrayNotHasKey('content', $revision->metadata);
        $this->assertArrayNotHasKey('views', $revision->metadata);
    }

    /** @test */
    public function it_can_save_belongs_to_relations_when_creating_a_revision()
    {
        $model = new class extends Post {
            public function getRevisionOptions() : RevisionOptions
            {
                return parent::getRevisionOptions()->relationsToRevision('author');
            }
        };

        $this->makeModels($model);
        $this->modifyPost();

        $revision = $this->post->revisions()->first();

        $this->assertArrayHasKey('author', $revision->metadata['relations']);
        $this->assertArrayHasKey('records', $revision->metadata['relations']['author']);
        $this->assertEquals(BelongsTo::class, $revision->metadata['relations']['author']['type']);

        $this->assertEquals($this->post->author->title, $revision->metadata['relations']['author']['records']['items'][0]['title']);
        $this->assertEquals($this->post->author->name, $revision->metadata['relations']['author']['records']['items'][0]['name']);
        $this->assertEquals($this->post->author->age, $revision->metadata['relations']['author']['records']['items'][0]['age']);
    }

    /** @test */
    public function it_stores_the_original_attribute_values_of_belongs_to_relations_when_creating_a_revision()
    {
        $model = new class extends Post {
            public function getRevisionOptions() : RevisionOptions
            {
                return parent::getRevisionOptions()->relationsToRevision('author');
            }
        };

        $this->makeModels($model);
        $this->modifyPost();

        $this->post->author()->update([
            'title' => 'Author title updated',
            'name' => 'Author name updated',
            'age' => 100,
        ]);

        $author = $this->post->author;
        $revision = $this->post->revisions()->first();

        $this->assertEquals('Author title updated', $author->title);
        $this->assertEquals('Author name updated', $author->name);
        $this->assertEquals('100', $author->age);

        $this->assertEquals('Author title', $revision->metadata['relations']['author']['records']['items'][0]['title']);
        $this->assertEquals('Author name', $revision->metadata['relations']['author']['records']['items'][0]['name']);
        $this->assertEquals('30', $revision->metadata['relations']['author']['records']['items'][0]['age']);
    }

    /** @test */
    public function it_rolls_back_belongs_to_relations_when_rolling_back_to_a_revision()
    {
        $model = new class extends Post {
            public function getRevisionOptions() : RevisionOptions
            {
                return parent::getRevisionOptions()->relationsToRevision('author');
            }
        };

        $this->makeModels($model);
        $this->modifyPost();

        $this->post->author()->update([
            'title' => 'Author title updated',
            'name' => 'Author name updated',
            'age' => 100,
        ]);

        $this->post->rollbackToRevision(
            $this->post->revisions()->first()
        );

        $author = $this->post->fresh()->author;

        $this->assertEquals('Author title', $author->title);
        $this->assertEquals('Author name', $author->name);
        $this->assertEquals('30', $author->age);
    }

    /** @test */
    public function it_can_save_has_one_relations_when_creating_a_revision()
    {
        $model = new class extends Post {
            public function getRevisionOptions() : RevisionOptions
            {
                return parent::getRevisionOptions()->relationsToRevision('reply');
            }
        };

        $this->makeModels($model);
        $this->modifyPost();

        $revision = $this->post->revisions()->first();

        $this->assertArrayHasKey('reply', $revision->metadata['relations']);
        $this->assertArrayHasKey('records', $revision->metadata['relations']['reply']);
        $this->assertEquals(HasOne::class, $revision->metadata['relations']['reply']['type']);

        $this->assertEquals($this->post->id, $revision->metadata['relations']['reply']['records']['items'][0]['post_id']);
        $this->assertEquals('Reply subject', $revision->metadata['relations']['reply']['records']['items'][0]['subject']);
        $this->assertEquals('Reply content', $revision->metadata['relations']['reply']['records']['items'][0]['content']);
    }

    /** @test */
    public function it_stores_the_original_attribute_values_of_has_one_relations_when_creating_a_revision()
    {
        $model = new class extends Post {
            public function getRevisionOptions() : RevisionOptions
            {
                return parent::getRevisionOptions()->relationsToRevision('reply');
            }
        };

        $this->makeModels($model);
        $this->modifyPost();

        $this->post->reply()->update([
            'subject' => 'Reply subject updated',
            'content' => 'Reply content updated',
        ]);

        $reply = $this->post->reply;
        $revision = $this->post->revisions()->first();

        $this->assertEquals('Reply subject updated', $reply->subject);
        $this->assertEquals('Reply content updated', $reply->content);

        $this->assertEquals('Reply subject', $revision->metadata['relations']['reply']['records']['items'][0]['subject']);
        $this->assertEquals('Reply content', $revision->metadata['relations']['reply']['records']['items'][0]['content']);
    }

    /** @test */
    public function it_rolls_back_has_one_relations_when_rolling_back_to_a_revision()
    {
        $model = new class extends Post {
            public function getRevisionOptions() : RevisionOptions
            {
                return parent::getRevisionOptions()->relationsToRevision('reply');
            }
        };

        $this->makeModels($model);
        $this->modifyPost();

        $this->post->reply()->update([
            'subject' => 'Reply subject updated',
            'content' => 'Reply content updated',
        ]);

        $this->post->rollbackToRevision(
            $this->post->revisions()->first()
        );

        $reply = $this->post->fresh()->reply;

        $this->assertEquals('Reply subject', $reply->subject);
        $this->assertEquals('Reply content', $reply->content);
    }

    /** @test */
    public function it_can_save_has_many_relations_when_creating_a_revision()
    {
        $model = new class extends Post {
            public function getRevisionOptions() : RevisionOptions
            {
                return parent::getRevisionOptions()->relationsToRevision('comments');
            }
        };

        $this->makeModels($model);
        $this->modifyPost();

        $revision = $this->post->revisions()->first();

        $this->assertArrayHasKey('comments', $revision->metadata['relations']);
        $this->assertArrayHasKey('records', $revision->metadata['relations']['comments']);
        $this->assertEquals(HasMany::class, $revision->metadata['relations']['comments']['type']);

        for ($i = 1; $i <= 3; $i++) {
            $comment = Comment::limit(1)->offset($i - 1)->first();

            $this->assertEquals($this->post->id, $revision->metadata['relations']['comments']['records']['items'][$i - 1]['post_id']);
            $this->assertEquals($comment->title, $revision->metadata['relations']['comments']['records']['items'][$i - 1]['title']);
            $this->assertEquals($comment->content, $revision->metadata['relations']['comments']['records']['items'][$i - 1]['content']);
            $this->assertEquals($comment->date, $revision->metadata['relations']['comments']['records']['items'][$i - 1]['date']);
            $this->assertEquals($comment->active, $revision->metadata['relations']['comments']['records']['items'][$i - 1]['active']);
        }
    }

    /** @test */
    public function it_stores_the_original_attribute_values_of_has_many_relations_when_creating_a_revision()
    {
        $model = new class extends Post {
            public function getRevisionOptions() : RevisionOptions
            {
                return parent::getRevisionOptions()->relationsToRevision('comments');
            }
        };

        $this->makeModels($model);
        $this->modifyPost();

        for ($i = 1; $i <= 3; $i++) {
            $this->post->comments()->limit(1)->offset($i - 1)->first()->update([
                'title' => 'Comment title ' . $i . ' updated',
                'content' => 'Comment content ' . $i . ' updated',
                'active' => false,
            ]);
        }

        $revision = $this->post->revisions()->first();

        for ($i = 1; $i <= 3; $i++) {
            $comment = $this->post->fresh()->comments()->limit(1)->offset($i - 1)->first();

            $this->assertEquals('Comment title ' . $i . ' updated', $comment->title);
            $this->assertEquals('Comment content ' . $i . ' updated', $comment->content);
            $this->assertEquals(0, $comment->active);

            $this->assertEquals('Comment title ' . $i, $revision->metadata['relations']['comments']['records']['items'][$i - 1]['title']);
            $this->assertEquals('Comment content ' . $i, $revision->metadata['relations']['comments']['records']['items'][$i - 1]['content']);
            $this->assertEquals(1, $revision->metadata['relations']['comments']['records']['items'][$i - 1]['active']);
        }
    }

    /** @test */
    public function it_rolls_back_has_many_relations_when_rolling_back_to_a_revision()
    {
        $model = new class extends Post {
            public function getRevisionOptions() : RevisionOptions
            {
                return parent::getRevisionOptions()->relationsToRevision('comments');
            }
        };

        $this->makeModels($model);
        $this->modifyPost();

        for ($i = 1; $i <= 3; $i++) {
            $this->post->comments()->limit(1)->offset($i - 1)->first()->update([
                'title' => 'Comment title ' . $i . ' updated',
                'content' => 'Comment content ' . $i . ' updated',
                'active' => false,
            ]);
        }

        $this->post->rollbackToRevision(
            $this->post->revisions()->first()
        );

        for ($i = 1; $i <= 3; $i++) {
            $comment = $this->post->fresh()->comments()->limit(1)->offset($i - 1)->first();

            $this->assertEquals('Comment title ' . $i, $comment->title);
            $this->assertEquals('Comment content ' . $i, $comment->content);
            $this->assertEquals(1, $comment->active);
        }
    }

    /** @test */
    public function it_can_save_belongs_to_many_relations_when_creating_a_revision()
    {
        $model = new class extends Post {
            public function getRevisionOptions() : RevisionOptions
            {
                return parent::getRevisionOptions()->relationsToRevision('tags');
            }
        };

        $this->makeModels($model);
        $this->modifyPost();

        $revision = $this->post->revisions()->first();

        $this->assertArrayHasKey('tags', $revision->metadata['relations']);
        $this->assertArrayHasKey('records', $revision->metadata['relations']['tags']);
        $this->assertArrayHasKey('pivots', $revision->metadata['relations']['tags']);
        $this->assertEquals(BelongsToMany::class, $revision->metadata['relations']['tags']['type']);

        for ($i = 1; $i <= 3; $i++) {
            $tag = Tag::find($i);

            $this->assertEquals($tag->name, $revision->metadata['relations']['tags']['records']['items'][$i - 1]['name']);
            $this->assertEquals($this->post->id, $revision->metadata['relations']['tags']['pivots']['items'][$i - 1]['post_id']);
            $this->assertEquals($tag->id, $revision->metadata['relations']['tags']['pivots']['items'][$i - 1]['tag_id']);
        }
    }

    /** @test */
    public function it_stores_the_original_pivot_values_of_belongs_to_many_relations_when_creating_a_revision()
    {
        $model = new class extends Post {
            public function getRevisionOptions() : RevisionOptions
            {
                return parent::getRevisionOptions()->relationsToRevision('tags');
            }
        };

        $this->makeModels($model);
        $this->modifyPost();

        $revision = $this->post->revisions()->first();

        $this->post->tags()->detach(
            $this->post->tags()->first()->id
        );

        $this->assertEquals(3, count($revision->metadata['relations']['tags']['pivots']['items']));
    }

    /** @test */
    public function it_rolls_back_belongs_to_many_relations_when_rolling_back_to_a_revision()
    {
        $model = new class extends Post {
            public function getRevisionOptions() : RevisionOptions
            {
                return parent::getRevisionOptions()->relationsToRevision('tags');
            }
        };

        $this->makeModels($model);
        $this->modifyPost();

        $this->post->tags()->detach(
            $this->post->tags()->first()->id
        );

        $this->assertEquals(2, $this->post->tags()->count());

        $this->post->rollbackToRevision(
            $this->post->revisions()->first()
        );

        $this->assertEquals(3, $this->post->tags()->count());
    }

    /**
     * @return void
     */
    protected function modifyPost()
    {
        $this->post->update([
            'name' => 'Another post name',
            'slug' => 'another-post-slug',
            'content' => 'Another post content',
            'votes' => 20,
            'views' => 200,
        ]);
    }

    /**
     * @return void
     */
    protected function modifyPostAgain()
    {
        $this->post->update([
            'name' => 'Yet another post name',
            'slug' => 'yet-another-post-slug',
            'content' => 'Yet another post content',
            'votes' => 30,
            'views' => 300,
        ]);
    }
}
