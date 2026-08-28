<?php

declare(strict_types=1);

namespace Storm\Symfony\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Storm\Symfony\EventTypeScanner;
use Storm\Symfony\Tests\Fixture\CaptureDone;
use Storm\Symfony\Tests\Fixture\ScannerFixtureEvent;
use Storm\Symfony\Tests\ScanFixture\AfterAnonymousEvent;
use Storm\Symfony\Tests\ScanFixture\AliasedEvent;
use Storm\Symfony\Tests\ScanFixture\DuplicateAliasEvent;
use Storm\Symfony\Tests\ScanFixture\FirstMultiEvent;
use Storm\Symfony\Tests\ScanFixture\Nested\Tests\UppercaseTestsEvent;
use Storm\Symfony\Tests\ScanFixture\PlainEvent;
use Storm\Symfony\Tests\ScanFixture\RenamedEvent;
use Storm\Symfony\Tests\ScanFixture\ScanEnumEvent;
use Storm\Symfony\Tests\ScanFixture\SecondMultiEvent;
use Storm\Symfony\Tests\ScanFixture\tests\LowercaseTestsEvent;

final class EventTypeScannerTest extends TestCase
{
    #[Test]
    public function finds_only_classes_carrying_the_event_type_attribute(): void
    {
        $found = new EventTypeScanner()->scan([__DIR__.'/ScanFixture']);

        $this->assertContains(AliasedEvent::class, $found);
        $this->assertContains(RenamedEvent::class, $found);
        $this->assertContains(DuplicateAliasEvent::class, $found);
        $this->assertNotContains(PlainEvent::class, $found); // no #[EventType]
    }

    #[Test]
    #[Group('adversarial')]
    public function test_directories_are_skipped_whatever_their_case(): void
    {
        // a fixture entering the production alias map is the poisoning the skip exists for, and an
        // app tree spells the segment tests/ as often as Tests/; both spellings must vanish
        $found = new EventTypeScanner()->scan([__DIR__.'/ScanFixture']);

        $this->assertNotContains(UppercaseTestsEvent::class, $found);
        $this->assertNotContains(LowercaseTestsEvent::class, $found);
    }

    #[Test]
    public function an_event_modeled_as_an_enum_is_found(): void
    {
        // an enum-blind walk drops a legal carrier from the map, surfacing only at the first
        // deserialize as UnknownEventType, the same silent symptom the every-class walk closes
        $found = new EventTypeScanner()->scan([__DIR__.'/ScanFixture']);

        $this->assertContains(ScanEnumEvent::class, $found);
    }

    #[Test]
    public function ignores_non_existent_paths(): void
    {
        $this->assertSame([], new EventTypeScanner()->scan(['/does/not/exist']));
    }

    #[Test]
    public function every_named_class_of_a_file_is_seen_not_just_the_first(): void
    {
        // stopping at the FIRST class of a file lets a plain helper ahead of the events hide
        // every #[EventType] that follows, surfacing only at the first deserialize as UnknownEventType
        $found = new EventTypeScanner()->scan([__DIR__.'/ScanFixture']);

        $this->assertContains(FirstMultiEvent::class, $found, 'the event after a plain helper');
        $this->assertContains(SecondMultiEvent::class, $found, 'the SECOND event of the same file');
    }

    #[Test]
    public function an_anonymous_class_ahead_of_an_event_hides_nothing(): void
    {
        // a walk that returns null for the whole file on the first anonymous class hides the event
        $found = new EventTypeScanner()->scan([__DIR__.'/ScanFixture']);

        $this->assertContains(AfterAnonymousEvent::class, $found);
    }

    #[Test]
    public function skips_anonymous_classes_and_bare_class_constants(): void
    {
        // robustness of the hand-rolled tokenizer: the scan tree holds a classless `scanner_noise.php`
        // with only a `Foo::class` constant, where the `class` token is part of `::class`, and an anonymous
        // class, `new class {}` with no name. Neither is a real declaration, so only the genuine
        // #[EventType] class comes back; the noise must not be registered nor crash the scan.
        $found = new EventTypeScanner()->scan([__DIR__.'/Fixture']);
        sort($found);

        $this->assertSame([CaptureDone::class, ScannerFixtureEvent::class], $found);
    }

    #[Test]
    public function yields_nothing_for_files_whose_only_class_tokens_are_tokenizer_edges(): void
    {
        // Two edges of the hand-rolled tokenizer that must resolve to NO class and never crash,
        // exercising the two dead-ends of classesInFile() directly through the real scan() entry point:
        //  - Empty_anonymous_class.php: `new class {}` as the last construct; nameAfter finds no name,
        //    so the walk skips it and collects nothing.
        //  - Only_class_constant.php:   a bare `Foo::class` and no declaration; the loop runs off the
        //    end and classesInFile returns its empty list.
        $found = new EventTypeScanner()->scan([__DIR__.'/ScanTokenizerEdge']);

        $this->assertSame([], $found);
    }

    #[Test]
    #[Group('adversarial')]
    public function does_not_register_a_class_an_anonymous_class_merely_mentions(): void
    {
        // ScanAnonPhantom/ declares NO class of its own; it holds an anonymous class whose body
        // mentions the real AliasedEvent. nameAfter must read the anonymous class as nameless and
        // register nothing, rather than wandering into the body and phantom-registering AliasedEvent,
        // whose real declaration lives in ScanFixture/, outside this scan.
        $found = new EventTypeScanner()->scan([__DIR__.'/ScanAnonPhantom']);

        $this->assertSame([], $found);
    }
}
