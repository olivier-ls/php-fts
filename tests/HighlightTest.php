<?php

declare(strict_types=1);

namespace Ols\PhpFts\Tests;

use Ols\PhpFts\Highlight;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The `highlight:` argument, in both the forms a caller may write it.
 */
class HighlightTest extends TestCase
{
    #[Test]
    public function a_plain_list_of_field_names_is_a_valid_highlight(): void
    {
        $highlight = Highlight::normalise(['title', 'description']);

        $this->assertNotNull($highlight);
        $this->assertSame(['title', 'description'], $highlight->fields);
    }

    #[Test]
    public function the_defaults_are_the_whole_field_marked_and_escaped(): void
    {
        $highlight = Highlight::fields(['title']);

        $this->assertSame('<mark>', $highlight->open);
        $this->assertSame('</mark>', $highlight->close);
        $this->assertNull($highlight->window);
        $this->assertTrue($highlight->escape);
        $this->assertFalse($highlight->offsets);
    }

    #[Test]
    public function asking_for_nothing_normalises_to_nothing(): void
    {
        // Null rather than an empty object, so that a search can skip the
        // work with a null check instead of inspecting the request.
        $this->assertNull(Highlight::normalise([]));
        $this->assertNull(Highlight::normalise(Highlight::fields([])));
    }

    #[Test]
    public function a_field_asked_for_twice_is_highlighted_once(): void
    {
        $this->assertSame(['title'], Highlight::fields(['title', 'title'])->fields);
    }

    #[Test]
    public function options_are_immutable(): void
    {
        $base    = Highlight::fields(['title']);
        $derived = $base->tags('<b>', '</b>')->excerpt(30)->raw();

        $this->assertSame('<mark>', $base->open);
        $this->assertNull($base->window);
        $this->assertTrue($base->escape);

        $this->assertSame('<b>', $derived->open);
        $this->assertSame(30, $derived->window);
        $this->assertFalse($derived->escape);
        $this->assertSame(['title'], $derived->fields);
    }

    #[Test]
    public function full_undoes_an_excerpt(): void
    {
        $this->assertNull(Highlight::fields(['title'])->excerpt(10)->full()->window);
    }

    #[Test]
    public function a_window_is_at_least_one_character(): void
    {
        $this->assertSame(1, Highlight::fields(['title'])->excerpt(0)->window);
        $this->assertSame(1, Highlight::fields(['title'])->excerpt(-5)->window);
    }

    #[Test]
    public function the_default_window_is_the_documented_one(): void
    {
        $this->assertSame(
            Highlight::DEFAULT_WINDOW,
            Highlight::fields(['title'])->excerpt()->window
        );
    }
}
