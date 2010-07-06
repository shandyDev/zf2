<?php
/**
 * Zend Framework
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://framework.zend.com/license/new-bsd
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@zend.com so we can send you a copy immediately.
 *
 * @category   Zend
 * @package    Zend_Feed
 * @copyright  Copyright (c) 2005-2010 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 * @version    $Id$
 */

/**
 * @namespace
 */
namespace Zend\Feed\Entry;
use Zend\Feed;

/**
 * Concrete class for working with Atom entries.
 *
 * @uses       DOMDocument
 * @uses       \Zend\Feed\Entry\AbstractEntry
 * @uses       \Zend\Feed\Exception
 * @category   Zend
 * @package    Zend_Feed
 * @copyright  Copyright (c) 2005-2010 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */
class Atom extends AbstractEntry
{
    /**
     * Content-Type
     */
    const CONTENT_TYPE = 'application/atom+xml';

    /**
     * Root XML element for Atom entries.
     *
     * @var string
     */
    protected $_rootElement = 'entry';

    /**
     * Root namespace for Atom entries.
     *
     * @var string
     */
    protected $_rootNamespace = 'atom';


    /**
     * Delete an atom entry.
     *
     * Delete tries to delete this entry from its feed. If the entry
     * does not contain a link rel="edit", we throw an error (either
     * the entry does not yet exist or this is not an editable
     * feed). If we have a link rel="edit", we do the empty-body
     * HTTP DELETE to that URI and check for a response of 2xx.
     * Usually the response would be 204 No Content, but the Atom
     * Publishing Protocol permits it to be 200 OK.
     *
     * @return void
     * @throws \Zend\Feed\Exception
     */
    public function delete()
    {
        // Look for link rel="edit" in the entry object.
        $deleteUri = $this->link('edit');
        if (!$deleteUri) {
            throw new Feed\Exception('Cannot delete entry; no link rel="edit" is present.');
        }

        // DELETE
        $client = Feed\Feed::getHttpClient();
        do {
            $client->setUri($deleteUri);
            if (Feed\Feed::getHttpMethodOverride()) {
                $client->setHeader('X-HTTP-Method-Override', 'DELETE');
                $response = $client->request('POST');
            } else {
                $response = $client->request('DELETE');
            }
            $httpStatus = $response->getStatus();
            switch ((int) $httpStatus / 100) {
                // Success
                case 2:
                    return true;
                // Redirect
                case 3:
                    $deleteUri = $response->getHeader('Location');
                    continue;
                // Error
                default:
                    throw new Feed\Exception("Expected response code 2xx, got $httpStatus");
            }
        } while (true);
    }


    /**
     * Save a new or updated Atom entry.
     *
     * Save is used to either create new entries or to save changes to
     * existing ones. If we have a link rel="edit", we are changing
     * an existing entry. In this case we re-serialize the entry and
     * PUT it to the edit URI, checking for a 200 OK result.
     *
     * For posting new entries, you must specify the $postUri
     * parameter to save() to tell the object where to post itself.
     * We use $postUri and POST the serialized entry there, checking
     * for a 201 Created response. If the insert is successful, we
     * then parse the response from the POST to get any values that
     * the server has generated: an id, an updated time, and its new
     * link rel="edit".
     *
     * @param  string $postUri Location to POST for creating new entries.
     * @return void
     * @throws \Zend\Feed\Exception
     */
    public function save($postUri = null)
    {
        if ($this->id()) {
            // If id is set, look for link rel="edit" in the
            // entry object and PUT.
            $editUri = $this->link('edit');
            if (!$editUri) {
                throw new Feed\Exception('Cannot edit entry; no link rel="edit" is present.');
            }

            $client = Feed\Feed::getHttpClient();
            $client->setUri($editUri);
            if (Feed\Feed::getHttpMethodOverride()) {
                $client->setHeaders(array('X-HTTP-Method-Override: PUT',
                    'Content-Type: ' . self::CONTENT_TYPE));
                $client->setRawData($this->saveXML());
                $response = $client->request('POST');
            } else {
                $client->setHeaders('Content-Type', self::CONTENT_TYPE);
                $client->setRawData($this->saveXML());
                $response = $client->request('PUT');
            }
            if ($response->getStatus() !== 200) {
                throw new Feed\Exception('Expected response code 200, got ' . $response->getStatus());
            }
        } else {
            if ($postUri === null) {
                throw new Feed\Exception('PostURI must be specified to save new entries.');
            }
            $client = Feed\Feed::getHttpClient();
            $client->setUri($postUri);
            $client->setHeaders('Content-Type', self::CONTENT_TYPE);
            $client->setRawData($this->saveXML());
            $response = $client->request('POST');

            if ($response->getStatus() !== 201) {
                throw new Feed\Exception('Expected response code 201, got '
                                              . $response->getStatus());
            }
        }

        // Update internal properties using $client->responseBody;
        @ini_set('track_errors', 1);
        $newEntry = new \DOMDocument;
        $status = @$newEntry->loadXML($response->getBody());
        @ini_restore('track_errors');

        if (!$status) {
            // prevent the class to generate an undefined variable notice (ZF-2590)
            if (!isset($php_errormsg)) {
                if (function_exists('xdebug_is_enabled')) {
                    $php_errormsg = '(error message not available, when XDebug is running)';
                } else {
                    $php_errormsg = '(error message not available)';
                }
            }

            throw new Feed\Exception('XML cannot be parsed: ' . $php_errormsg);
        }

        $newEntry = $newEntry->getElementsByTagName($this->_rootElement)->item(0);
        if (!$newEntry) {
            throw new Feed\Exception('No root <feed> element found in server response:'
                                          . "\n\n" . $client->responseBody);
        }

        if ($this->_element->parentNode) {
            $oldElement = $this->_element;
            $this->_element = $oldElement->ownerDocument->importNode($newEntry, true);
            $oldElement->parentNode->replaceChild($this->_element, $oldElement);
        } else {
            $this->_element = $newEntry;
        }
    }


    /**
     * Easy access to <link> tags keyed by "rel" attributes.
     *
     * If $elt->link() is called with no arguments, we will attempt to
     * return the value of the <link> tag(s) like all other
     * method-syntax attribute access. If an argument is passed to
     * link(), however, then we will return the "href" value of the
     * first <link> tag that has a "rel" attribute matching $rel:
     *
     * $elt->link(): returns the value of the link tag.
     * $elt->link('self'): returns the href from the first <link rel="self"> in the entry.
     *
     * @param  string $rel The "rel" attribute to look for.
     * @return mixed
     */
    public function link($rel = null)
    {
        if ($rel === null) {
            return parent::__call('link', null);
        }

        // index link tags by their "rel" attribute.
        $links = parent::__get('link');
        if (!is_array($links)) {
            if ($links instanceof Feed\Element) {
                $links = array($links);
            } else {
                return $links;
            }
        }

        foreach ($links as $link) {
            if (empty($link['rel'])) {
                $link['rel'] = 'alternate'; // see Atom 1.0 spec
            }
            if ($rel == $link['rel']) {
                return $link['href'];
            }
        }

        return null;
    }

}