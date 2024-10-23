<?php

namespace FeedCleaner\Structure;

class Entry
{
    public ?string $title = null;
    public ?string $id = null;
    public $updated;
    public ?string $author_name = null;
    public ?string $author_id = null;
    public ?string $content = null;
    public ?string $link = null;

    public array $categories = [];
    public array $links = [];
}
