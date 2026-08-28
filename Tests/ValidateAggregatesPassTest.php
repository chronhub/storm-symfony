<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Storm\Symfony\Compiler\ValidateAggregatesPass;
use Storm\Symfony\Tests\Fixture\Article;
use Storm\Symfony\Tests\Fixture\ArticleId;
use Storm\Symfony\Tests\Fixture\SnapshotArticle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ValidateAggregatesPassTest extends TestCase
{
    #[Test]
    public function passes_a_well_formed_aggregate(): void
    {
        $this->expectNotToPerformAssertions();

        $this->process([Article::class => ['id' => ArticleId::class, 'category' => 'article']]);
    }

    #[Test]
    public function no_op_without_the_parameter(): void
    {
        $this->expectNotToPerformAssertions();

        new ValidateAggregatesPass()->process(new ContainerBuilder);
    }

    #[Test]
    public function rejects_a_missing_aggregate_class(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        // the message, not just the type: every guard in this pass throws the same class, so a test
        // reading the type alone stays green while an EARLIER guard answers in place of this one
        $this->expectExceptionMessageIsOrContains('aggregate class "App\\Nope" does not exist');

        $this->process(['App\\Nope' => ['id' => ArticleId::class, 'category' => 'x']]);
    }

    #[Test]
    public function rejects_an_aggregate_not_implementing_the_contract(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains(sprintf('"%s" must implement', stdClass::class));

        $this->process([stdClass::class => ['id' => ArticleId::class, 'category' => 'x']]);
    }

    #[Test]
    public function rejects_a_missing_id_class(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        // one matcher, not two: a second message expectation REPLACES the first, and the guard
        // right after this one names the same class in its own refusal
        $this->expectExceptionMessageMatches('/id class "App\\\\NopeId".+does not exist/');

        $this->process([Article::class => ['id' => 'App\\NopeId', 'category' => 'x']]);
    }

    #[Test]
    public function rejects_an_id_not_implementing_the_contract(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches(sprintf('/id class "%s".+must implement/', preg_quote(stdClass::class, '/')));

        $this->process([Article::class => ['id' => stdClass::class, 'category' => 'x']]);
    }

    #[Test]
    public function rejects_a_snapshot_min_interval_above_max_age(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains('must be <= max_age_seconds');

        $this->process([SnapshotArticle::class => ['id' => ArticleId::class, 'category' => 'article', 'snapshot' => ['min_interval_seconds' => 7200, 'max_age_seconds' => 3600]]]);
    }

    #[Test]
    public function passes_a_snapshot_min_interval_within_max_age(): void
    {
        $this->expectNotToPerformAssertions();

        $this->process([SnapshotArticle::class => ['id' => ArticleId::class, 'category' => 'article', 'snapshot' => ['min_interval_seconds' => 3600, 'max_age_seconds' => 86400]]]);
    }

    #[Test]
    public function accepts_a_frequency_floor_equal_to_the_staleness_ceiling(): void
    {
        // the bound itself: the rule is <=, so the equal pair is the highest legal floor. Written
        // as a refusal only, a rule tightened to < would read exactly like this one.
        $this->expectNotToPerformAssertions();

        $this->process([SnapshotArticle::class => ['id' => ArticleId::class, 'category' => 'article', 'snapshot' => ['min_interval_seconds' => 3600, 'max_age_seconds' => 3600]]]);
    }

    #[Test]
    public function a_floor_declared_without_a_ceiling_is_no_pair_to_compare(): void
    {
        // both knobs are optional and the comparison needs BOTH; with either absent there is no
        // ordering to enforce, and a guard that fired on one alone would refuse a legal config.
        $this->expectNotToPerformAssertions();

        $this->process([SnapshotArticle::class => ['id' => ArticleId::class, 'category' => 'article', 'snapshot' => ['min_interval_seconds' => 7200]]]);
        $this->process([SnapshotArticle::class => ['id' => ArticleId::class, 'category' => 'article', 'snapshot' => ['max_age_seconds' => 3600]]]);
    }

    #[Test]
    public function rejects_two_aggregates_sharing_a_category(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([
            Article::class => ['id' => ArticleId::class, 'category' => 'shared'],
            SnapshotArticle::class => ['id' => ArticleId::class, 'category' => 'shared'],
        ]);
    }

    /**
     * @param  array<string, array{id: string, category: string, snapshot?: array{max_age_seconds?: int, min_interval_seconds?: int}}>  $aggregates
     */
    private function process(array $aggregates): void
    {
        $container = new ContainerBuilder;
        $container->setParameter('storm.aggregates', $aggregates);

        new ValidateAggregatesPass()->process($container);
    }

    #[Test]
    public function rejects_a_non_canonical_category(): void
    {
        // StreamName lowercases at runtime while the sweep compares the RAW value; 'Order' would
        // write 'order-*' streams the sweep never finds, silently, forever
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/not canonical/');

        $this->process([Article::class => ['id' => ArticleId::class, 'category' => 'Article']]);
    }

    #[Test]
    public function rejects_a_whitespace_category(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([Article::class => ['id' => ArticleId::class, 'category' => ' article ']]);
    }

    #[Test]
    public function rejects_an_invalid_category(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process([Article::class => ['id' => ArticleId::class, 'category' => '9bad']]);
    }

    #[Test]
    public function rejects_a_snapshot_block_on_a_non_snapshotable_aggregate(): void
    {
        // the sweep silently skips a non-snapshotable class forever; a mismatched block is a config
        // bug the build must surface, not a preference
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/snapshot block/');

        $this->process([Article::class => ['id' => ArticleId::class, 'category' => 'article', 'snapshot' => ['threshold' => 5]]]);
    }
}
