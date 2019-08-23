<?php

namespace FeedCleaner\Structure;

class Channel
{
    public $title;
    public $subtitle;
    public $link;
    public $id;
    public $generator;
    public $updated;
    public $logo;
    public $items = [];

    public $base;
}
