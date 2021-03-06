<?php
/**
 * @copyright Copyright (c) 2017 Robin Appelman <robin@icewind.nl>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace SearchDAV\DAV;

use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\INode;
use Sabre\DAV\Node;
use Sabre\DAV\PropFind;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\DAV\Xml\Element\Response;
use Sabre\DAV\Xml\Response\MultiStatus;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use Sabre\Xml\ParseException;
use Sabre\Xml\Writer;
use SearchDAV\Backend\ISearchBackend;
use SearchDAV\Backend\SearchPropertyDefinition;
use SearchDAV\Backend\SearchResult;
use SearchDAV\XML\BasicSearch;
use SearchDAV\XML\BasicSearchSchema;
use SearchDAV\XML\PropDesc;
use SearchDAV\XML\QueryDiscoverResponse;
use SearchDAV\XML\Scope;
use SearchDAV\XML\SupportedQueryGrammar;

class SearchPlugin extends ServerPlugin {
	/** @var Server */
	private $server;

	/** @var ISearchBackend */
	private $searchBackend;

	/** @var QueryParser */
	private $queryParser;

	/** @var PathHelper */
	private $pathHelper;

	/** @var SearchHandler */
	private $search;

	/** @var DiscoverHandler */
	private $discover;

	public function __construct(ISearchBackend $searchBackend) {
		$this->searchBackend = $searchBackend;
		$this->queryParser = new QueryParser();
	}

	public function initialize(Server $server) {
		$this->server = $server;
		$this->pathHelper = new PathHelper($server);
		$this->search = new SearchHandler($this->searchBackend, $this->pathHelper, $server);
		$this->discover = new DiscoverHandler($this->searchBackend, $this->pathHelper, $this->queryParser);
		$server->on('method:SEARCH', [$this, 'searchHandler']);
		$server->on('afterMethod:OPTIONS', [$this, 'optionHandler']);
		$server->on('propFind', [$this, 'propFindHandler']);
	}

	public function propFindHandler(PropFind $propFind, INode $node) {
		if ($propFind->getPath() === $this->searchBackend->getArbiterPath()) {
			$propFind->handle('{DAV:}supported-query-grammar-set', new SupportedQueryGrammar());
		}
	}

	/**
	 * SEARCH is allowed for users files
	 *
	 * @param string $uri
	 * @return array
	 */
	public function getHTTPMethods($uri) {
		$path = $this->pathHelper->getPathFromUri($uri);
		if ($this->searchBackend->getArbiterPath() === $path) {
			return ['SEARCH'];
		} else {
			return [];
		}
	}

	public function optionHandler(RequestInterface $request, ResponseInterface $response) {
		if ($request->getPath() === $this->searchBackend->getArbiterPath()) {
			$response->addHeader('DASL', '<DAV:basicsearch>');
		}
	}

	public function searchHandler(RequestInterface $request, ResponseInterface $response) {
		$contentType = $request->getHeader('Content-Type');

		// Currently we only support xml search queries
		if ((strpos($contentType, 'text/xml') === false) && (strpos($contentType, 'application/xml') === false)) {
			return true;
		}

		if ($request->getPath() !== $this->searchBackend->getArbiterPath()) {
			return true;
		}

		try {
			$xml = $this->queryParser->parse(
				$request->getBody(),
				$request->getUrl(),
				$documentType
			);
		} catch (ParseException $e) {
			$response->setStatus(400);
			$response->setBody('Parse error: ' . $e->getMessage());
			return false;
		}

		switch ($documentType) {
			case '{DAV:}searchrequest':
				return $this->search->handleSearchRequest($xml, $response);
			case '{DAV:}query-schema-discovery':
				return $this->discover->handelDiscoverRequest($xml, $request, $response);
			default:
				$response->setStatus(400);
				$response->setBody('Unexpected document type: ' . $documentType . ' for this Content-Type, expected {DAV:}searchrequest or {DAV:}query-schema-discovery');
				return false;
		}
	}
}
