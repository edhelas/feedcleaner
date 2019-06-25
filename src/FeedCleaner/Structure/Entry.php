<?php

namespace FeedCleaner\Structure;

class Entry
{
    public $title;
    public $id;
    public $updated;
    public $author_name;
    public $author_id;
    public $content;
    public $link;

    public $categories = [];
    public $links = [];
}
