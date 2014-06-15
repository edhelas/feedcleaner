<?php

namespace FeedCleaner;

class Channel {
    public $title;
    public $subtitle;
    public $link;
    public $id;
    public $generator;
    public $updated;
    public $logo;
    public $items = array();
}

class Entry {
    public $title;
    public $id;
    public $updated;
    public $author_name;
    public $author_id;
    public $content;
    public $link;
    
    public $categories = array();
    public $links = array();
}

class Link {
    public $rel;
    public $type;
    public $href;
}
