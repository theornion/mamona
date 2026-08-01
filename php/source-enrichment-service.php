<?php

declare(strict_types=1);

const SOURCE_PAGE_MAX_BYTES = 1572864;
const SOURCE_PAGE_TIMEOUT_SECONDS = 20;
const SOURCE_PAGE_MAX_REDIRECTS = 3;

function source_absolute_url(string $base, string $candidate): string
{
    $candidate = html_entity_decode(trim($candidate), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (filter_var($candidate, FILTER_VALIDATE_URL)) return $candidate;
    if ($candidate === '' || str_starts_with($candidate, '#') || str_starts_with($candidate, 'mailto:')) return '';
    $parts = parse_url($base);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return '';
    if (str_starts_with($candidate, '//')) return $parts['scheme'] . ':' . $candidate;
    $root = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    if (str_starts_with($candidate, '/')) return $root . $candidate;
    $path = (string) ($parts['path'] ?? '/');
    return $root . preg_replace('#/[^/]*$#', '/', $path) . $candidate;
}

function fetch_source_page(string $url, int $redirectsRemaining = SOURCE_PAGE_MAX_REDIRECTS): array
{
    [$url, $host, $address] = assert_public_feed_url($url);
    $robotsUrl = 'https://' . $host . '/robots.txt';
    $robots = source_http_request($robotsUrl, 262144, 8, 0, false);
    if (($robots['status'] ?? 0) === 200 && preg_match('#(?ims)^User-agent:\s*\*.*?^Disallow:\s*/\s*$#', (string) $robots['body']) === 1) {
        throw new RuntimeException('robots.txt zabrania pobierania strony docelowej.');
    }
    return source_http_request($url, SOURCE_PAGE_MAX_BYTES, SOURCE_PAGE_TIMEOUT_SECONDS, $redirectsRemaining, true, $address);
}

function source_http_request(string $url, int $maxBytes, int $timeout, int $redirects, bool $requireHtml, ?string $resolvedAddress = null): array
{
    [$url, $host, $address] = assert_public_feed_url($url);
    $address = $resolvedAddress ?: $address;
    $headers = []; $body = ''; $tooLarge = false;
    $curl = curl_init($url);
    $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>false, CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>(int)app_config('feed_connect_timeout_seconds'),
        CURLOPT_TIMEOUT=>$timeout, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_LOW_SPEED_LIMIT=>(int)app_config('feed_low_speed_limit'), CURLOPT_LOW_SPEED_TIME=>(int)app_config('feed_low_speed_time_seconds'), CURLOPT_ENCODING=>'',
        CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_USERAGENT=>'Mamona-Content-Studio/1.0 (+https://mamona.pl/kontakt)', CURLOPT_HTTPHEADER=>['Accept: text/html,application/xhtml+xml,text/plain;q=0.2'],
        CURLOPT_RESOLVE=>[$host.':'.$port.':'.(str_contains($address, ':') ? '['.$address.']' : $address)],
        CURLOPT_HEADERFUNCTION=>static function($h,string $line) use (&$headers): int { $p=strpos($line,':'); if($p!==false)$headers[strtolower(trim(substr($line,0,$p)))]=trim(substr($line,$p+1)); return strlen($line); },
        CURLOPT_WRITEFUNCTION=>static function($h,string $chunk) use (&$body,&$tooLarge,$maxBytes): int { if(strlen($body)+strlen($chunk)>$maxBytes){$tooLarge=true;return 0;} $body.=$chunk;return strlen($chunk); }]);
    $ca = trim((string) app_config('feed_ca_bundle')); if ($ca !== '') curl_setopt($curl, CURLOPT_CAINFO, $ca);
    $ok=curl_exec($curl); $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE); $type=strtolower((string)curl_getinfo($curl,CURLINFO_CONTENT_TYPE));
    $declaredLength=(int)curl_getinfo($curl,CURLINFO_CONTENT_LENGTH_DOWNLOAD); $downloaded=(int)curl_getinfo($curl,CURLINFO_SIZE_DOWNLOAD); $error=curl_error($curl); curl_close($curl);
    if($tooLarge) throw new RuntimeException('Strona źródłowa przekracza limit rozmiaru.');
    if($ok===false) throw new RuntimeException('Błąd pobierania strony źródłowej: '.$error);
    if($status>=300 && $status<400){ $location=source_absolute_url($url,(string)($headers['location']??'')); if($redirects<=0||$location===''||!str_starts_with($location,'https://')) throw new RuntimeException('Niedozwolone lub zbyt liczne przekierowanie źródła.'); assert_public_feed_url($location); return source_http_request($location,$maxBytes,$timeout,$redirects-1,$requireHtml); }
    if($status<200||$status>=300) throw new RuntimeException('Strona źródłowa zwróciła HTTP '.$status.'.');
    if($requireHtml && !str_contains($type,'html')) throw new RuntimeException('Strona docelowa nie jest dokumentem HTML.');
    if($declaredLength>0 && $downloaded<$declaredLength) throw new RuntimeException('Odebrano tylko część strony źródłowej.');
    return ['status'=>$status,'body'=>$body,'content_type'=>$type,'url'=>$url,'transfer_complete'=>true,'declared_bytes'=>$declaredLength,'downloaded_bytes'=>$downloaded];
}

function source_host_matches(string $host, string $allowedHost): bool
{
    $host = strtolower(rtrim($host, '.')); $allowedHost = strtolower(rtrim($allowedHost, '.'));
    return $host === $allowedHost || str_ends_with($host, '.' . $allowedHost);
}

function source_allowed_hosts(array $configuredSource): array
{
    $hosts=[];
    foreach (['website_url','feed_url'] as $field) {
        $host=strtolower((string)parse_url((string)($configuredSource[$field]??''),PHP_URL_HOST));
        if($host!=='') $hosts[$host]=true;
    }
    return array_keys($hosts);
}

function source_meta_value(DOMXPath $xp, array $queries): string
{
    foreach($queries as $query){$node=$xp->query($query)->item(0);$value=trim((string)($node?->nodeValue??''));if($value!=='')return html_entity_decode($value,ENT_QUOTES|ENT_HTML5,'UTF-8');}
    return '';
}

/** Evaluate the already-fetched discovery document; this never promotes a site based on its TLD. */
function verify_discovery_institutional_page(array $page, array $feedItem, array $configuredSource): ?array
{
    if((int)($configuredSource['is_primary']??0)!==1 || (int)($configuredSource['credibility_level']??0)<4) return null;
    if(($page['transfer_complete']??true)!==true || !empty($page['truncated'])) return null;
    $pageUrl=(string)($page['url']??$feedItem['source_url']??'');
    if(!str_starts_with($pageUrl,'https://')) return null;
    $allowed=source_allowed_hosts($configuredSource); $pageHost=(string)parse_url($pageUrl,PHP_URL_HOST);
    if($allowed===[] || !array_filter($allowed,static fn(string $host):bool=>source_host_matches($pageHost,$host))) return null;
    try{assert_public_feed_url($pageUrl);}catch(Throwable){return null;}
    $html=(string)($page['body']??''); if(strlen($html)<1000) return null;
    $doc=new DOMDocument();$old=libxml_use_internal_errors(true);$loaded=$doc->loadHTML($html,LIBXML_NONET|LIBXML_COMPACT);libxml_clear_errors();libxml_use_internal_errors($old);if(!$loaded)return null;
    $xp=new DOMXPath($doc);
    $canonical=source_absolute_url($pageUrl,source_meta_value($xp,['//link[translate(@rel,"CANONICAL","canonical")="canonical"]/@href','//meta[@property="og:url"]/@content']));
    if($canonical==='')$canonical=$pageUrl;
    $canonicalHost=(string)parse_url($canonical,PHP_URL_HOST);
    if(!str_starts_with($canonical,'https://') || !array_filter($allowed,static fn(string $host):bool=>source_host_matches($canonicalHost,$host))) return null;
    try{assert_public_feed_url($canonical);}catch(Throwable){return null;}
    $title=source_meta_value($xp,['//meta[@property="og:title"]/@content','//meta[@name="twitter:title"]/@content','//h1[1]','//title[1]']);
    $published=source_meta_value($xp,['//meta[@property="article:published_time"]/@content','//meta[@name="date"]/@content','//*[@itemprop="datePublished"]/@content','//*[@itemprop="datePublished"]/@datetime','//time[@datetime][1]/@datetime']);
    if($published===''&&!empty($feedItem['published_at']))$published=(string)$feedItem['published_at'];
    $publisher=trim((string)($configuredSource['name']??''));
    $articleType=strtolower(source_meta_value($xp,['//meta[@property="og:type"]/@content']));
    $path=strtolower((string)parse_url($canonical,PHP_URL_PATH));
    if($title===''||mb_strlen($title)<20||$publisher===''||$published===''||preg_match('~/(?:tag|tags|category|search|archive)(?:/|$)|/(?:404|error)(?:/|$)~',$path)) return null;
    foreach($xp->query('//script|//style|//noscript|//nav|//footer|//header|//aside|//*[@role="navigation"]|//*[@role="contentinfo"]|//*[contains(concat(" ",normalize-space(@class)," ")," cookie ")]')?:[] as $node){$node->parentNode?->removeChild($node);}
    $root=$xp->query('//article[1]')->item(0)?:$xp->query('//*[@itemprop="articleBody"][1]')->item(0)?:$xp->query('//main[1]')->item(0)?:$xp->query('//body[1]')->item(0);
    if(!$root)return null;
    $text=trim((string)preg_replace('/\s+/u',' ',$root->textContent));
    $words=preg_split('/\s+/u',$text,-1,PREG_SPLIT_NO_EMPTY)?:[];
    $looksError=preg_match('/\b(page not found|access denied|an error occurred|enable javascript)\b/i',mb_substr($text,0,500))===1;
    if($looksError||mb_strlen($text)<800||count($words)<120) return null;
    $excerpt=mb_substr($text,0,1800); // Evidence allowance, never a full article copy.
    return ['source_kind'=>'institutional_release','is_primary'=>1,'is_peer_reviewed'=>0,'publisher'=>$publisher,'title'=>$title,'published_at'=>$published,
        'identifier_type'=>'','identifier_value'=>'','canonical_url'=>$canonical,'verification_method'=>'configured_primary_domain+https_tls+canonical+article_structure+content_completeness',
        'verification_status'=>'verified','completeness'=>'complete','evidence'=>['configured_source_id'=>(int)($configuredSource['id']??0),'allowed_hosts'=>$allowed,'final_url'=>$pageUrl,'canonical_url'=>$canonical,'article_type'=>$articleType?:'unspecified','published_metadata'=>true,'content_characters'=>mb_strlen($text),'content_words'=>count($words),'transfer_complete'=>true],
        'content_excerpt'=>$excerpt];
}

function extract_source_candidates(string $html, string $pageUrl): array
{
    $doc=new DOMDocument(); $old=libxml_use_internal_errors(true); $doc->loadHTML($html, LIBXML_NONET|LIBXML_COMPACT); libxml_clear_errors(); libxml_use_internal_errors($old); $xp=new DOMXPath($doc);
    $candidates=[]; $add=static function(array &$items,string $url,string $method,string $identifier='') use($pageUrl): void { $url=source_absolute_url($pageUrl,$url); if($url===''||!str_starts_with($url,'https://'))return; $items[$url]=['url'=>$url,'method'=>$method,'identifier'=>$identifier]; };
    foreach($xp->query('//a[@href]')?:[] as $node){ $href=$node->getAttribute('href'); $text=trim($node->textContent); if(preg_match('~doi\.org/|pubmed\.ncbi\.nlm\.nih\.gov|arxiv\.org/(?:abs|pdf)/|zenodo\.org/(?:record|records)/~i',$href.' '.$text)) $add($candidates,$href,'explicit_link'); }
    foreach($xp->query('//*[@itemprop="citation" or @itemprop="sameAs"]')?:[] as $node) $add($candidates,$node->getAttribute('href')?:$node->getAttribute('content'),'schema_org');
    $text=$doc->textContent; preg_match_all('~\b10\.\d{4,9}/[-._;()/:A-Z0-9]+~i',$text,$matches); foreach(array_unique($matches[0]??[]) as $doi){$doi=rtrim($doi,'.;,)]');$add($candidates,'https://doi.org/'.$doi,'doi_text',$doi);}
    return array_values($candidates);
}

function verify_source_candidate(array $candidate, ?callable $registry = null): ?array
{
    $url=(string)($candidate['url']??''); try { assert_public_feed_url($url); } catch(Throwable){ return null; }
    $doi=''; if(preg_match('~(?:doi\.org/)?(10\.\d{4,9}/[^?#\s]+)~i',$url,$m)) $doi=urldecode(rtrim($m[1],'.;,)]'));
    $kind=str_contains($url,'arxiv.org/')?'preprint':($doi!==''?'journal_article':'institutional_release');
    $registry ??= 'official_registry_lookup';
    $record=$registry($kind,$doi!==''?$doi:$url);
    if(!is_array($record)) return null;
    $canonical=(string)($record['canonical_url']??''); if(!filter_var($canonical,FILTER_VALIDATE_URL)||!str_starts_with($canonical,'https://')) return null;
    try { assert_public_feed_url($canonical); } catch(Throwable){ return null; }
    return ['source_kind'=>$kind,'is_primary'=>(int)($record['is_primary']??1),'is_peer_reviewed'=>$kind==='preprint'?0:(int)($record['is_peer_reviewed']??0),
        'publisher'=>(string)($record['publisher']??''),'title'=>(string)($record['title']??''),'published_at'=>$record['published_at']??null,
        'identifier_type'=>$doi!==''?'doi':(string)($record['identifier_type']??''),'identifier_value'=>$doi!==''?$doi:(string)($record['identifier_value']??''),
        'canonical_url'=>$canonical,'verification_method'=>(string)($record['verification_method']??'official_registry'),
        'verification_status'=>'verified','completeness'=>(string)($record['completeness']??'complete'),
        'evidence'=>(array)($record['evidence']??[]),'content_excerpt'=>mb_substr(trim((string)($record['content_excerpt']??'')),0,3000)];
}

function official_registry_lookup(string $kind, string $identifier): ?array
{
    try {
        if ($kind === 'journal_article') {
            $response = source_http_request('https://api.crossref.org/works/' . rawurlencode($identifier), 524288, 12, 1, false);
            $message = json_decode((string) $response['body'], true)['message'] ?? null;
            if (!is_array($message) || strcasecmp((string) ($message['DOI'] ?? ''), $identifier) !== 0) return null;
            $date = $message['published']['date-parts'][0] ?? [];
            return ['canonical_url'=>'https://doi.org/'.$message['DOI'], 'title'=>(string)(($message['title'][0]??'')),
                'publisher'=>(string)($message['publisher']??''), 'published_at'=>$date ? implode('-', array_pad($date,3,1)) : null,
                'is_primary'=>1, 'is_peer_reviewed'=>1, 'verification_method'=>'crossref_api', 'completeness'=>'complete',
                'evidence'=>['Crossref DOI match','title and publisher metadata'], 'content_excerpt'=>(string)($message['abstract']??($message['title'][0]??''))];
        }
        if ($kind === 'preprint' && preg_match('~arxiv\.org/(?:abs|pdf)/([^/?#]+)~', $identifier, $match)) {
            $id=preg_replace('/\.pdf$/','',$match[1]);
            $response=source_http_request('https://export.arxiv.org/api/query?id_list='.rawurlencode($id),524288,12,1,false);
            if(!str_contains((string)$response['body'],'<id>http://arxiv.org/abs/'.$id)) return null;
            preg_match('~<title>(.*?)</title>~s',(string)$response['body'],$title); preg_match('~<summary>(.*?)</summary>~s',(string)$response['body'],$summary);
            return ['canonical_url'=>'https://arxiv.org/abs/'.$id,'title'=>trim(strip_tags($title[1]??$id)),'publisher'=>'arXiv','identifier_type'=>'arxiv','identifier_value'=>$id,
                'is_primary'=>1,'is_peer_reviewed'=>0,'verification_method'=>'arxiv_api','completeness'=>'complete','evidence'=>['arXiv identifier match'],'content_excerpt'=>trim(strip_tags($summary[1]??''))];
        }
        if ($kind === 'institutional_release') {
            $response=source_http_request($identifier,SOURCE_PAGE_MAX_BYTES,12,1,true);
            $doc=new DOMDocument(); @$doc->loadHTML((string)$response['body'],LIBXML_NONET|LIBXML_COMPACT); $xp=new DOMXPath($doc);
            $title=trim((string)($xp->query('//meta[@property="og:title"]/@content')->item(0)?->nodeValue ?: $doc->getElementsByTagName('title')->item(0)?->textContent));
            $description=trim((string)($xp->query('//meta[@name="description"]/@content')->item(0)?->nodeValue));
            if($title===''||mb_strlen($description)<40) return null;
            return ['canonical_url'=>(string)$response['url'],'title'=>$title,'publisher'=>(string)parse_url((string)$response['url'],PHP_URL_HOST),
                'is_primary'=>1,'is_peer_reviewed'=>0,'verification_method'=>'official_institution_page','completeness'=>'complete',
                'evidence'=>['HTTPS institutional page and canonical metadata'],'content_excerpt'=>mb_substr($description,0,3000)];
        }
    } catch (Throwable) { return null; }
    return null;
}

function enrich_topic_sources(int $topicId, ?callable $pageFetcher = null, ?callable $registry = null): array
{
    $pageFetcher ??= static fn(string $url): array => fetch_source_page($url);
    $result = ['verified' => 0, 'failed' => 0, 'retryable_failed' => 0, 'permanent_failed' => 0, 'errors' => []];
    foreach (topic_feed_items($topicId) as $item) {
        try {
            $page = $pageFetcher((string) $item['source_url']);
            $configuredSource=find_technical_source((int)$item['technical_source_id']);
            if(is_array($configuredSource)){
                $institutional=verify_discovery_institutional_page($page,$item,$configuredSource);
                if($institutional!==null){persist_verified_research_source($topicId,(int)$item['id'],$institutional);$result['verified']++;}
            }
            $candidates = extract_source_candidates((string) ($page['body'] ?? ''), (string) ($page['url'] ?? $item['source_url']));
            foreach ($candidates as $candidate) {
                $verified = verify_source_candidate($candidate, $registry);
                if ($verified === null) continue; // Gemini never sees unverified candidates.
                persist_verified_research_source($topicId, (int) $item['id'], $verified);
                $result['verified']++;
            }
        } catch (Throwable $exception) {
            $failure = source_enrichment_failure_details($exception);
            $result['failed']++;
            $result[$failure['retryable'] ? 'retryable_failed' : 'permanent_failed']++;
            $result['errors'][] = ['feed_item_id' => (int) $item['id'], ...$failure];
        }
    }
    return $result;
}

function source_enrichment_failure_details(Throwable $exception): array
{
    $message = mb_substr($exception->getMessage(), 0, 500);
    preg_match('/HTTP\s+(\d{3})/i', $message, $match);
    $status = (int) ($match[1] ?? 0);
    $lower = mb_strtolower($message);
    $retryable = $status === 429 || $status >= 500
        || str_contains($lower, 'timeout') || str_contains($lower, 'timed out')
        || str_contains($lower, 'couldn\'t connect') || str_contains($lower, 'temporary');
    $code = match (true) {
        $status === 403 => 'http_forbidden',
        $status === 404 => 'http_not_found',
        $status === 429 => 'http_rate_limited',
        $status >= 500 => 'http_server_error',
        str_contains($lower, 'robots.txt') => 'robots_disallowed',
        str_contains($lower, 'nie jest dokumentem html') => 'unsupported_content_type',
        str_contains($lower, 'przekracza limit rozmiaru') => 'content_too_large',
        $retryable => 'transport_retryable',
        default => 'source_unavailable',
    };
    return ['error' => $message, 'code' => $code, 'http_status' => $status,
        'content_type' => '', 'downloaded_bytes' => 0, 'retryable' => $retryable];
}

function persist_verified_research_source(int $topicId, ?int $feedItemId, array $source): int
{
    $fingerprint=hash('sha256',strtolower($source['canonical_url'].'|'.$source['identifier_value'].'|'.$source['title']));
    $sql='INSERT INTO verified_research_sources (topic_id,discovery_feed_item_id,source_kind,is_primary,is_peer_reviewed,publisher,title,published_at,identifier_type,identifier_value,canonical_url,verification_method,verification_status,completeness,evidence_json,content_excerpt,content_fingerprint) VALUES (:topic,:feed,:kind,:primary,:reviewed,:publisher,:title,:published,:id_type,:id_value,:url,:method,:status,:complete,:evidence,:excerpt,:fingerprint) ON CONFLICT(topic_id,canonical_url) DO UPDATE SET verification_method=excluded.verification_method,verification_status=excluded.verification_status,completeness=excluded.completeness,evidence_json=excluded.evidence_json,content_excerpt=excluded.content_excerpt,content_fingerprint=excluded.content_fingerprint,updated_at=CURRENT_TIMESTAMP';
    bueno_database()->prepare($sql)->execute([':topic'=>$topicId,':feed'=>$feedItemId,':kind'=>$source['source_kind'],':primary'=>$source['is_primary'],':reviewed'=>$source['is_peer_reviewed'],':publisher'=>$source['publisher'],':title'=>$source['title'],':published'=>$source['published_at'],':id_type'=>$source['identifier_type'],':id_value'=>$source['identifier_value'],':url'=>$source['canonical_url'],':method'=>$source['verification_method'],':status'=>$source['verification_status'],':complete'=>$source['completeness'],':evidence'=>generation_json($source['evidence']),':excerpt'=>$source['content_excerpt'],':fingerprint'=>$fingerprint]);
    return (int)bueno_database()->lastInsertId();
}

function list_verified_research_sources(int $topicId): array
{
    $s=bueno_database()->prepare('SELECT * FROM verified_research_sources WHERE topic_id=:id AND verification_status="verified" ORDER BY is_primary DESC,id'); $s->execute([':id'=>$topicId]); return $s->fetchAll();
}

/** Legal feed metadata retained even when fetching the linked full page is unavailable. */
function list_safe_feed_research_sources(int $topicId): array
{
    $sources = [];
    foreach (topic_feed_items($topicId) as $item) {
        $configured = find_technical_source((int) ($item['technical_source_id'] ?? 0));
        $url = trim((string) ($item['source_url'] ?? ''));
        $title = trim((string) ($item['title'] ?? ''));
        $summary = trim((string) ($item['summary'] ?? ''));
        if (!is_array($configured) || (int) ($configured['is_active'] ?? 0) !== 1
            || !str_starts_with($url, 'https://') || mb_strlen($title) < 20 || mb_strlen($summary) < 40) continue;
        $lastStatus = (int) ($configured['last_http_status'] ?? 0);
        if ($lastStatus !== 0 && ($lastStatus < 200 || $lastStatus >= 400)) continue;
        $sources[] = ['verification_status' => 'feed_verified', 'completeness' => 'excerpt_only',
            'source_kind' => 'rss_discovery', 'is_primary' => 0, 'publisher' => (string) ($item['source_name'] ?? $configured['name']),
            'canonical_url' => $url, 'title' => $title, 'content_excerpt' => $summary,
            'published_at' => $item['published_at'] ?? null, 'feed_item_id' => (int) $item['id'],
            'technical_source_id' => (int) $configured['id'], 'feed_http_status' => $lastStatus,
            'scope' => 'title_summary_date_link_only'];
    }
    return $sources;
}

function research_policy_for_topic(int $topicId, string $riskLevel = 'low', bool $controversial = false, bool $contradictory = false): array
{
    $verified = list_verified_research_sources($topicId);
    $policy = research_policy_decision($verified, $riskLevel, $controversial, $contradictory);
    if (($policy['decision'] ?? '') === 'continue' || $contradictory) return $policy + ['material_scope' => 'verified_full'];
    $feed = list_safe_feed_research_sources($topicId);
    if ($feed !== [] && !$controversial && !in_array($riskLevel, ['high', 'health'], true)) {
        return ['decision' => 'continue', 'code' => 'safe_feed_excerpt',
            'reason' => 'Pełna strona nie jest wymagana: niski poziom ryzyka pozwala kontynuować wyłącznie na tytule i opisie z legalnie pobranego feedu.',
            'manual_single_source_allowed' => true, 'material_scope' => 'feed_excerpt_only',
            'confidence_cap' => 'medium', 'requires_conservative_research' => true,
            'enrichment_gap' => count($feed) < 2 ? 'second_independent_source_optional' : ''];
    }
    if ($feed !== []) {
        return ['decision' => 'blocked', 'code' => 'second_independent_source_required',
            'reason' => 'Materiał feedowy zachowano, ale poziom ryzyka wymaga niezależnego potwierdzenia.',
            'manual_single_source_allowed' => false, 'material_scope' => 'feed_excerpt_only',
            'enrichment_gap' => 'second_independent_source_required'];
    }
    return ['decision' => 'blocked', 'code' => 'no_source_material',
        'reason' => 'Temat nie ma zweryfikowanego źródła ani kompletnego tytułu i opisu z aktywnego feedu.',
        'manual_single_source_allowed' => false, 'material_scope' => 'none', 'enrichment_gap' => 'any_legal_material'];
}

function research_policy_decision(array $sources, string $riskLevel='low', bool $controversial=false, bool $contradictory=false): array
{
    $verified=array_values(array_filter($sources,static fn($s)=>($s['verification_status']??'')==='verified'));
    $completePrimary=array_values(array_filter($verified,static fn($s)=>(int)($s['is_primary']??0)===1&&($s['completeness']??'')==='complete'));
    $publishers=array_unique(array_filter(array_map(static fn($s)=>strtolower(trim((string)($s['publisher']??parse_url((string)($s['canonical_url']??''),PHP_URL_HOST)))), $verified)));
    $requiresTwo=$controversial||$contradictory||in_array($riskLevel,['high','health'],true);
    if($contradictory) return ['decision'=>'review','code'=>'contradiction','reason'=>'Źródła zawierają sprzeczności wymagające decyzji redakcyjnej.','manual_single_source_allowed'=>false];
    if($completePrimary===[]) return ['decision'=>'blocked','code'=>'no_complete_primary','reason'=>'Brak kompletnego, zweryfikowanego źródła pierwotnego; skrót RSS nie wystarcza.','manual_single_source_allowed'=>false];
    if($requiresTwo && count($publishers)<2) return ['decision'=>'review','code'=>'second_independent_source_required','reason'=>'Ryzyko lub kontrowersyjność wymaga dwóch niezależnych wiarygodnych źródeł.','manual_single_source_allowed'=>false];
    return ['decision'=>'continue','code'=>count($publishers)>=2?'two_independent_sources':'one_complete_primary','reason'=>count($publishers)>=2?'Dwa niezależne zweryfikowane źródła są zgodne.':'Niski poziom ryzyka i jedno kompletne, zweryfikowane źródło pierwotne.','manual_single_source_allowed'=>count($publishers)===1];
}
