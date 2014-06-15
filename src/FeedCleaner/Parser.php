<?php

namespace FeedCleaner;

use FeedCleaner\Feed;

class Parser {
    private $_xml;
    private $_channel;

    private $_item;

    public function setXML($xml) {
        $xml = $content = preg_replace("/(<\/?)(\w+):([^>]*>)/", "$1$2$3", $xml);
        $this->_xml = simplexml_load_string($xml);
    }

    
    private function getBaseUri() {
        $pageURL = 'http';
        
        if(isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] == "on") {$pageURL .= "s";}
        
        $pageURL .= "://";
        
        if ($_SERVER["SERVER_PORT"] != "80") {
            $pageURL .= $_SERVER["SERVER_NAME"].":".$_SERVER["SERVER_PORT"].$_SERVER["REQUEST_URI"];
        } else {
            $pageURL .= $_SERVER["SERVER_NAME"].$_SERVER["REQUEST_URI"];
        }
        
        return $pageURL;
    }

    private function parseLink($link) {
        $l = new Link;
        
        $l->rel     = 'enclosure';
        if(isset($link->attributes()->type))
            $l->type    = $link->attributes()->type;
        else {
            if($link->attributes()->medium == 'image')
                $l->type    = 'image/jpeg';
            else
                $l->type    = 'text/html';
        }

        $l->href    = $link->attributes()->url;

        return $l;
    }

    private function testElement($element) {
        if(isset($element) && $element && (string)$element != '')
            return (string)$element;
        else
            return false;
    }

    public function parse() {
        $channel = new Channel;
        
        switch($this->_xml->getName()) {
            // We have a RSS feed !
            case 'rss' :
            case 'rdfRDF' :
                $channel->title     = (string)$this->_xml->channel->title;
                $channel->subtitle  = (string)$this->_xml->channel->description;
                $channel->link      = (string)$this->_xml->channel->link;

                if(isset($channel->link))
                    $channel->id        = $this->generateUUID($channel->link.$channel->link);
                else
                    $channel->id        = $this->generateUUID();
                
                $channel->generator = (string)$this->_xml->channel->generator;

                // We try to get the last feed update
                $channel->updated = strtotime((string)$this->_xml->channel->lastBuildDate);
                if($channel->updated == false)
                    $channel->updated = strtotime((string)$this->_xml->channel->pubDate);

                if($channel->updated = strtotime('now'))
                    $channel->updated = false;

                // We try to grab a logo
                if(isset($this->_xml->channel->image) && isset($this->_xml->channel->image->url))
                    $channel->logo = (string)$this->_xml->channel->image->url;

                // Atom namespace
                if($this->_xml->channel->atomicon)
                    $channel->logo = (string)$this->_xml->channel->atomicon;
                if($this->_xml->channel->atomlink)
                    $channel->link = (string)$this->_xml->channel->atomlink->attributes()->href;
                    
                // Well, fu** you RSS
                if(isset($this->_xml->item))
                    $entries = $this->_xml->item;
                else
                    $entries = $this->_xml->channel->item;

                foreach($entries as $entry) {
                    $ent = new Entry;
                    $ent->title     = (string)$entry->title;

                    // We grab the content                    
                    if($this->testElement($entry->contentencoded))
                        $ent->content = $this->testElement($entry->contentencoded);
                    elseif($this->testElement($entry->content))
                        $ent->content = $this->testElement($entry->content);
                    elseif($this->testElement($entry->description))
                        $ent->content = $this->testElement($entry->description);

                    if(isset($entry->guid))
                        $ent->id        = $this->generateUUID((string)$entry->guid);
                    elseif(isset($entry->link))
                        $ent->id        = $this->generateUUID((string)$entry->link);
                        
                    $ent->updated   = strtotime((string)$entry->pubDate);
                    if($ent->updated == false)
                        $ent->updated = strtotime((string)$entry->dcdate);
                    
                    $ent->link      = (string)$entry->link;

                    $ent->author_name = (string)$entry->author;

                    if($channel->updated == false)
                        $channel->updated = $ent->updated;

                    foreach($entry->children() as $link) {
                        if(substr($link->getName(), 0, 5) == 'media') {                           
                            switch($link->getName()) {
                                case 'mediacontent' :
                                    $l = $this->parseLink($link);

                                    array_push($ent->links, $l);
                                    break;
                                case 'mediagroup' :
                                    foreach($link->children() as $grouped_link) {
                                        $l = $this->parseLink($grouped_link);

                                        array_push($ent->links, $l);                                        
                                    }
                                    break;
                            }
                        }

                        if($link->getName() == 'category') {
                            array_push($ent->categories, (string)$link);
                        }

                        if($link->getName() == 'comments') {
                            $l = new Link;
                            $l->rel     = 'replies';
                            $l->type    = 'text/html';
                            $l->href    = $link->attributes()->url;
                        }
                        
                        if($link->getName() == 'enclosure') {
                            $l = new Link;

                            $l->rel  = 'enclosure';
                            $l->href = (string)$link->attributes()->url;
                            $l->type = (string)$link->attributes()->type;

                            array_push($ent->links, $l);
                        }
                        
                        if(substr($link->getName(), 0, 2) == 'dc') {
                            switch($link->getName()) {
                                case 'dccreator' :
                                    $ent->author_name = (string)$link;
                                    break;
                            }
                        }
                        
                    }

                    array_push($channel->items, $ent);
                }

                break;

            // We have an Atom feed
            case 'feed' :
                $channel->title     = (string)$this->_xml->title;
                $channel->subtitle  = (string)$this->_xml->subtitle;
                $channel->link      = (string)$this->_xml->link->attributes()->href;
                
                $channel->id        = $this->generateUUID($channel->link);

                $channel->generator = (string)$this->_xml->generator;
                
                $channel->logo      = (string)$this->_xml->logo;

                $channel->updated = strtotime((string)$this->_xml->updated);
                
                foreach($this->_xml->entry as $entry) {
                    $ent = new Entry;
                    $ent->title     = (string)$entry->title;
                    $ent->content   = (string)$entry->content;
                    if($ent->content == false)
                        $ent->content   = (string)$entry->summary;
                    
                    $ent->id        = $this->generateUUID((string)$entry->id);
                    $ent->updated   = strtotime((string)$entry->updated);

                    $ent->author_name = (string)$entry->author->name;
                    
                    array_push($channel->items, $ent);
                }
                
                break;
        }

        $this->_channel = $channel;
    }

    public function transform($transformation) {
        foreach($this->_channel->items as $item) {
            $xslt =
                '<?xml version="1.0" encoding="UTF-8"?>
                <xsl:stylesheet xmlns:xsl="http://www.w3.org/1999/XSL/Transform" version="1.0">
                    <xsl:output method="html" indent="yes" />
                    
                    <xsl:template match="//table">
                        <xsl:apply-templates select="tr"/>
                    </xsl:template>

                    
                    <xsl:template match="tr">
                        <a href="{td[1]/a/@href}">
                            <img src="{td[2]/a[2]/@href}" alt="{td[1]/a/img/@alt}" title="{td[1]/a/img/@title}"/><br />
                            <img src="{td[1]/a/img/@src}" alt="{td[1]/a/img/@alt}" title="{td[1]/a/img/@title}"/>
                        </a>
                    </xsl:template>

                    <xsl:template match="//div">
                        <xsl:copy-of select="./node()" />
                    </xsl:template>
                    
                    <xsl:template match="a[contains(., \'[link]\')]">
                        <br /><a href="{@href}">Link</a><br />
                    </xsl:template>
                </xsl:stylesheet>
                ';

            /* Allocation d'un analyseur XSLT */
            $xp = new XsltProcessor();

            $xsl = new DomDocument;
            $xsl->loadXML($xslt);

            $xml = new DomDocument;
            $xml->loadHTML($item->content);

            
            $xpath = new DOMXpath($xml);

            $l = new Link;
            $l->rel  = 'enclosure';
            $l->href = $xpath->query('//a[2]/@href')->item(0)->value;
            $l->type = 'text/html';

            array_push($item->links, $l);
            
            $l = new Link;
            $l->rel  = 'replies';
            $l->href = $xpath->query('//a[3]/@href')->item(0)->value;
            $l->type = 'text/html';

            array_push($item->links, $l);

            $item->author_name = trim((string)$xpath->query('//td[2]/a[1]')->item(0)->nodeValue);

            $proc = new XSLTProcessor();

            // On précise au parseur que l'on veut utiliser des fonctions PHP en XSL
            $proc->registerPHPFunctions();

            $proc->importStyleSheet($xsl); 
            $item->content = $proc->transformToXML($xml);
        }
    }

    /*
     * Generate a standard UUID
     */
    private function generateUUID($string = false) {
        if($string != false && strlen($string) > 8)
            $data = strrev($string);
        else
            $data = openssl_random_pseudo_bytes(16);

        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0010
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public function generate() {
        header("Content-Type: application/atom+xml; charset=UTF-8");
        header("Last-Modified: " . date("D, d M Y H:i:s", (int)$this->_channel->updated) . " GMT");
        ?>
<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
    <title><?php echo $this->_channel->title; ?></title>
    <updated><?php echo date(DATE_ATOM, (int)$this->_channel->updated); ?></updated>
    <link rel="self" href="<?php echo $this->getBaseUri(); ?>"/>
    
    <subtitle><?php echo $this->_channel->subtitle; ?></subtitle>
    <?php if($this->_channel->logo) { ?>
    <logo><?php echo $this->_channel->logo; ?></logo>
    <?php } ?>
    <generator uri="http://launchpad.net/feedcleaner" version="0.1">
      FeedCleaner
    </generator>
    
    <id>urn:uuid:<?php echo $this->_channel->id; ?></id>
    <?php foreach($this->_channel->items as $item) { ?>
    <entry>
        <title><?php echo $item->title; ?></title>
        <id>urn:uuid:<?php echo $item->id; ?></id>
        <updated><?php echo date(DATE_ATOM, (int)$item->updated); ?></updated>
        <content type="html">
            <![CDATA[<?php echo $item->content; ?>]]>
        </content>

        <author>
            <name><?php echo $item->author_name; ?></name>
        </author>
        <?php foreach($item->categories as $category) { ?>
            <category term="<?php echo $category; ?>"/>
        <?php } ?>
        <?php foreach($item->links as $link) { ?>
            <link rel="<?php echo $link->rel; ?>" type="<?php echo $link->type; ?>" href="<?php echo $link->href; ?>"/>
        <?php } ?>
        <link rel="alternate" type="text/html" href="<?php echo $item->link; ?>"/>
    </entry>
    <?php } ?>
</feed>
        <?php
    }
}
