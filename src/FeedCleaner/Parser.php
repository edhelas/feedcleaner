<?php

namespace FeedCleaner;

use FeedCleaner\Structure\Channel;
use FeedCleaner\Structure\Entry;
use FeedCleaner\Structure\Link;

use Ramsey\Uuid\Uuid;

class Parser
{
    private $_xml;
    private $_channel;

    private $_item;

    public function setXML($xml)
    {
        $xml = $content = preg_replace("/(<\/?)(\w+):([^>]*>)/", "$1$2$3", $xml);
        $this->_xml = simplexml_load_string($xml);
    }


    private function getBaseUri()
    {
        $pageURL = 'http';

        if (isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] == "on") {$pageURL .= "s";}

        $pageURL .= "://";

        if ($_SERVER["SERVER_PORT"] != "80") {
            $pageURL .= $_SERVER["SERVER_NAME"].":".$_SERVER["SERVER_PORT"].$_SERVER["REQUEST_URI"];
        } else {
            $pageURL .= $_SERVER["SERVER_NAME"].$_SERVER["REQUEST_URI"];
        }

        return $pageURL;
    }

    private function getTypeFromPath($path)
    {
        $ext = pathinfo($this->removeParams($path), PATHINFO_EXTENSION);

        switch ($ext) {
            case 'jpeg':
            case 'jpg':
                $type = 'image/jpeg';
                break;

            case 'webp':
                $type = 'image/webp';
                break;

            case 'png':
                $type = 'image/png';
                break;

            case 'gif':
            case 'gifv':
                $type = 'image/gif';
                break;

            default:
                $type = 'text/html';
                break;
        }

        return $type;
    }

    private function parseLink($link)
    {
        $l = new Link;

        $l->rel     = 'enclosure';
        if (isset($link->attributes()->type)) {
            $l->type    = $link->attributes()->type;
        } else {
            $l->type = $this->getTypeFromPath($link->attributes()->url);
        }

        $l->href = $link->attributes()->url;

        return $l;
    }

    private function testElement($element)
    {
        if (isset($element) && $element && (string)$element != '') {
            return (string)$element;
        } else {
            return false;
        }
    }

    private function removeParams($url, bool $soft = false)
    {
        if (empty($url)) return $url;

        $query = '';
        $parts = parse_url($url);

        // With soft, keep the first parameter (often item id)
        if (isset($parts['query']) && $soft) {
            $params = [];
            parse_str($parts['query'], $params);

            if ($soft && count($params) == 1) {
                $query = '?'.http_build_query($params);
            }
        }

        return (array_key_exists('scheme', $parts) && array_key_exists('path', $parts))
            ? $parts['scheme'].'://'.$parts['host'].$parts['path'].$query
            : $url;
    }

    public function parse()
    {
        $channel = new Channel;

        switch($this->_xml->getName()) {
            // We have a RSS feed !
            case 'rss' :
            case 'rdfRDF' :
                $channel->title     = html_entity_decode((string)$this->_xml->channel->title);
                $channel->subtitle  = html_entity_decode((string)$this->_xml->channel->description);
                $channel->link      = (string)$this->_xml->channel->link;

                $url = parse_url($channel->link);
                if ($url) {
                    $channel->base = $url['scheme'].'://'.$url['host'];
                }

                if (isset($channel->link)) {
                    $channel->id        = Uuid::uuid5(Uuid::NAMESPACE_DNS, $channel->link);
                } else {
                    $channel->id        = Uuid::uuid5(Uuid::NAMESPACE_DNS, $channel->title);
                }

                $channel->generator = (string)$this->_xml->channel->generator;

                // We try to get the last feed update
                $channel->updated = strtotime((string)$this->_xml->channel->lastBuildDate);
                if ($channel->updated == false) {
                    $channel->updated = strtotime((string)$this->_xml->channel->pubDate);
                }

                if ($channel->updated = strtotime('now')) {
                    $channel->updated = false;
                }

                // We try to grab a logo
                if (isset($this->_xml->channel->image) && isset($this->_xml->channel->image->url)) {
                    $channel->logo = $this->removeParams((string)$this->_xml->channel->image->url);
                }

                // Atom namespace
                if ($this->_xml->channel->atomicon) {
                    $channel->logo = $this->removeParams((string)$this->_xml->channel->atomicon);
                }

                if ($this->_xml->channel->atomlink) {
                    $channel->link = (string)$this->_xml->channel->atomlink->attributes()->href;
                }

                // Well, fu** you RSS
                if (isset($this->_xml->item)) {
                    $entries = $this->_xml->item;
                } else {
                    $entries = $this->_xml->channel->item;
                }

                foreach ($entries as $entry) {
                    $ent = new Entry;
                    $ent->title     = html_entity_decode((string)$entry->title);

                    // We grab the content
                    if ($this->testElement($entry->contentencoded)) {
                        $ent->content = $this->testElement($entry->contentencoded);
                    } elseif ($this->testElement($entry->content)) {
                        $ent->content = $this->testElement($entry->content);
                    } elseif ($this->testElement($entry->description)) {
                        $ent->content = $this->testElement($entry->description);
                    }

                    $ent->content = $this->contentClean($ent->content, $channel->base);

                    if (isset($entry->guid)) {
                        $ent->id        = Uuid::uuid5(Uuid::NAMESPACE_DNS, (string)$entry->guid);
                    } elseif (isset($entry->link)) {
                        $ent->id        = Uuid::uuid5(Uuid::NAMESPACE_DNS, (string)$entry->link);
                    }

                    $ent->updated   = strtotime((string)$entry->pubDate);
                    if ($ent->updated == false)
                        $ent->updated = strtotime((string)$entry->dcdate);
                    if ($ent->updated == false)
                        $ent->updated = strtotime((string)$entry->dccreated);

                    $ent->link      = (string)$entry->link;

                    $ent->author_name = (string)$entry->author;

                    if ($channel->updated == false) {
                        $channel->updated = $ent->updated;
                    }

                    foreach ($entry->children() as $link) {
                        if (substr($link->getName(), 0, 5) == 'media') {
                            switch($link->getName()) {
                                case 'mediacontent' :
                                    $l = $this->parseLink($link);

                                    array_push($ent->links, $l);
                                    break;
                                case 'mediagroup' :
                                    foreach ($link->children() as $grouped_link) {
                                        $l = $this->parseLink($grouped_link);

                                        array_push($ent->links, $l);
                                    }
                                    break;
                            }
                        }

                        if ($link->getName() == 'category') {
                            array_push($ent->categories, html_entity_decode((string)$link));
                        }

                        if ($link->getName() == 'comments') {
                            $l = new Link;
                            $l->rel     = 'replies';
                            $l->type    = 'text/html';
                            $l->href    = $link->attributes()->url;
                        }

                        if ($link->getName() == 'enclosure') {
                            $l = new Link;

                            $l->rel  = 'enclosure';
                            $l->href = (string)$link->attributes()->url;
                            $l->type = (string)$link->attributes()->type;

                            array_push($ent->links, $l);
                        }

                        if (substr($link->getName(), 0, 2) == 'dc') {
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
                if ($this->_xml->link) {
                    $channel->link  = (string)$this->_xml->link->attributes()->href;

                    $url = parse_url($channel->link);
                    if ($url) {
                        $channel->base = $url['scheme'].'://'.$url['host'];
                    }
                }

                $channel->id        = Uuid::uuid5(Uuid::NAMESPACE_DNS, $channel->link);
                $channel->generator = (string)$this->_xml->generator;
                $channel->logo      = $this->removeParams((string)$this->_xml->logo);
                $channel->updated = strtotime((string)$this->_xml->updated);

                foreach ($this->_xml->entry as $entry) {
                    $ent = new Entry;
                    $ent->title     = (string)$entry->title;
                    $ent->content   = (string)$entry->content;
                    if ($ent->content == false) {
                        $ent->content   = (string)$entry->summary;
                    }

                    $ent->content = $this->contentClean($ent->content, $channel->base);
                    $ent->id        = Uuid::uuid5(Uuid::NAMESPACE_DNS, (string)$entry->id);
                    $ent->updated   = strtotime((string)$entry->updated);
                    $ent->author_name = (string)$entry->author->name;

                    foreach ($entry->link as $link) {
                        if ($link->attributes()->rel
                        && $link->attributes()->type) {
                            $l = new Link;

                            $l->rel   = (string)$link->attributes()->rel;
                            $l->href  = (string)$link->attributes()->href;
                            $l->type  = (string)$link->attributes()->type;

                            if ($link->attributes()->title) {
                                $l->title = (string)$link->attributes()->title;
                            }

                            array_push($ent->links, $l);
                        }
                    }

                    array_push($channel->items, $ent);
                }

                break;
        }

        $this->_channel = $channel;
    }

    public function transform($transformation = false)
    {
        /**
            <xsl:template match="tr">
                <a href="{td[1]/a/@href}">
                    <img src="{td[1]/a/img/@src}" alt="{td[1]/a/img/@alt}" title="{td[1]/a/img/@title}"/>
                </a>
            </xsl:template>
         */

        foreach ($this->_channel->items as $item) {
            $xslt =
                '<?xml version="1.0" encoding="UTF-8"?>
                <xsl:stylesheet xmlns:xsl="http://www.w3.org/1999/XSL/Transform" version="1.0">
                    <xsl:output method="html" indent="yes" />

                    <xsl:template match="//table">
                        <xsl:apply-templates select="tr"/>
                    </xsl:template>

                    <xsl:template match="//div">
                        <xsl:copy-of select="./node()" />
                    </xsl:template>

                    <xsl:template match="a[contains(., \'[link]\')]">
                        <br /><a href="{@href}">Link</a><br />
                    </xsl:template>
                </xsl:stylesheet>
                ';

            $xsl = new \DomDocument;
            $xsl->loadXML($xslt);

            $xml = new \DomDocument;
            $xml->loadHTML($item->content);

            $xpath = new \DOMXpath($xml);

            $img = $xpath->query('//td[2]/span[1]/a[1]/@href');
            if ($img->item(0) != null) {
                $href = $img->item(0)->value;

                $ext = $this->getTypeFromPath($href);

                $l = new Link;
                $l->rel  = 'enclosure';
                $l->href = $href;
                $l->type = $ext;

                array_push($item->links, $l);
            }

            /*$l = new Link;
            $l->rel  = 'replies';
            $l->href = $xpath->query('//a[3]/@href')->item(0)->value;
            $l->type = 'text/html';

            array_push($item->links, $l);*/

            $l = new Link;
            $l->rel  = 'alternate';
            $l->href = $xpath->query('//a[1]/@href')->item(0)->value;
            $l->type = 'text/html';

            array_push($item->links, $l);

            //$item->author_name = trim((string)$xpath->query('//td[2]/a[1]')->item(0)->nodeValue);

            $proc = new \XSLTProcessor();

            // On précise au parseur que l'on veut utiliser des fonctions PHP en XSL
            $proc->registerPHPFunctions();

            $proc->importStyleSheet($xsl);
            $item->content = $proc->transformToXML($xml);
        }

        $this->htmlClean();
    }

    private function htmlClean()
    {
        $this->_channel->title     = htmlentities($this->_channel->title);
        $this->_channel->subtitle  = htmlentities($this->_channel->subtitle);

        if (!is_string($this->_channel->link)) {
            $this->_channel->link      = htmlentities($this->_channel->link->attributes()->href);
        }

        $this->_channel->logo      = htmlentities($this->_channel->logo);
    }

    private function contentClean($string, $base = null)
    {
        $config = \HTMLPurifier_Config::createDefault();
        $config->set('HTML.Doctype', 'XHTML 1.1');
        $config->set('Cache.SerializerPath', '/tmp');
        $config->set('HTML.DefinitionID', 'html5-definitions');
        $config->set('HTML.DefinitionRev', 1);

        $config->set('URI.Base', $base);
        $config->set('URI.MakeAbsolute', true);

        $config->set('CSS.AllowedProperties', ['float']);
        if ($def = $config->maybeGetRawHTMLDefinition()) {
            $def->addElement('video', 'Block', 'Optional: (source, Flow) | (Flow, source) | Flow', 'Common', [
              'src' => 'URI',
              'type' => 'Text',
              'width' => 'Length',
              'height' => 'Length',
              'poster' => 'URI',
              'preload' => 'Enum#auto,metadata,none',
              'controls' => 'Bool',
            ]);
            $def->addElement('audio', 'Block', 'Optional: (source, Flow) | (Flow, source) | Flow', 'Common', [
              'src' => 'URI',
              'preload' => 'Enum#auto,metadata,none',
              'muted' => 'Bool',
              'controls' => 'Bool',
            ]);
            $def->addElement('source', 'Block', 'Flow', 'Common', [
              'src' => 'URI',
              'type' => 'Text',
            ]);
        }

        $purifier = new \HTMLPurifier($config);
        $trimmed = trim($purifier->purify($string));
        return preg_replace('#(\s*<br\s*/?>)*\s*$#i', '', $trimmed);
    }

    private function cleanXML($xml)
    {
        if ($xml != '') {
            $doc = new \DOMDocument('1.0');
            $doc->formatOutput = true;
            return $doc->saveXML();
        } else {
            return '';
        }
    }

    public function generate()
    {
        header("Content-Type: application/atom+xml; charset=UTF-8");
        header("Last-Modified: " . date("D, d M Y H:i:s", (int)$this->_channel->updated) . " GMT");

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $feed = $dom->createElementNS('http://www.w3.org/2005/Atom', 'feed');
        $dom->appendChild($feed);

        $title = $dom->createElement('title');
        $cdata = $dom->createCDATASection(trim($this->_channel->title));
        $title->appendChild($cdata);
        $feed->appendChild($title);

        $updated = $dom->createElement('updated', date(DATE_ATOM, (int)$this->_channel->updated));
        $feed->appendChild($updated);

        $link = $dom->createElement('link');
        $link->setAttribute('rel', 'self');
        $link->setAttribute('href', $this->getBaseUri());
        $feed->appendChild($link);

        if ($this->_channel->subtitle) {
            //$subtitle = $dom->createElement('subtitle', $this->_channel->subtitle);
            //$feed->appendChild($subtitle);
        }

        if ($this->_channel->logo) {
            $logo = $dom->createElement('logo', $this->_channel->logo);
            $feed->appendChild($logo);
        }

        $generator = $dom->createElement('generator', 'FeedCleaner');
        $generator->setAttribute('uri', 'https://github.com/edhelas/feedcleaner');
        $generator->setAttribute('version', '0.2');
        $feed->appendChild($generator);

        $id = $dom->createElement('id', 'urn:uuid:'.$this->_channel->id);
        $feed->appendChild($id);

        foreach ($this->_channel->items as $item) {
            $entry = $dom->createElement('entry');

            $title = $dom->createElement('title');
            $cdata = $dom->createCDATASection($item->title);
            $title->appendChild($cdata);
            $entry->appendChild($title);

            $id = $dom->createElement('id', 'urn:uuid:'.$item->id);
            $entry->appendChild($id);

            $updated = $dom->createElement('updated', date(DATE_ATOM, (int)$item->updated));
            $entry->appendChild($updated);

            $content = $dom->createElement('content');
            $cdata = $dom->createCDATASection($item->content);
            $content->appendChild($cdata);
            $content->setAttribute('type', 'html');
            $entry->appendChild($content);

            if ($item->author_name) {
                $author = $dom->createElement('author');
                $name = $dom->createElement('name');
                $nameContent = $dom->createTextNode($item->author_name);
                $name->appendChild($nameContent);
                $author->appendChild($name);
                $entry->appendChild($author);
            }

            foreach ($item->categories as $category) {
                $c = $dom->createElement('category');
                $c->setAttribute('term', (string)$category);
                $entry->appendChild($c);
            }

            foreach ($item->links as $link) {
                $l = $dom->createElement('link');
                $l->setAttribute('rel', $link->rel);
                $l->setAttribute('type', $link->type);
                $l->setAttribute('href', $this->removeParams($link->href));

                if ($link->title) {
                    $l->setAttribute('title', $link->title);
                }

                $entry->appendChild($l);
            }

            if ($item->link) {
                $l = $dom->createElement('link');
                $l->setAttribute('rel', 'alternate');
                $l->setAttribute('type', 'text/html');
                $l->setAttribute('href', $this->removeParams($item->link, true));
                $entry->appendChild($l);
            }

            $feed->appendChild($entry);
        }

        echo $dom->saveXML();
    }
}
