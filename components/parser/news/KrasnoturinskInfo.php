<?php

namespace app\components\parser\news;

use app\components\Helper;
use app\components\helper\Aleks007smolBaseParser;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use DateTime;
use DateTimeZone;
use Exception;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Парсер новостей из RSS ленты
 *
 */
class KrasnoturinskInfo extends Aleks007smolBaseParser implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    /**
     * Ссылка на главную страницу сайта
     */
    const MAIN_PAGE_URI = 'https://krasnoturinsk.info';

    /**
     * CSS класс, где хранится содержимое новости
     */
    const BODY_CONTAINER_CSS_SELECTOR = '.js-mediator-article';

    /**
     * CSS  класс для параграфов - цитат
     */
    const QUOTE_TAG = 'blockquote';

    /**
     * Классы эоементов, которые не нужно парсить, например блоки с рекламой и т.п.
     * в формате RegExp
     */
    const EXCLUDE_CSS_CLASSES_PATTERN = '';

    /**
     * Класс элемента после которого парсить страницу не имеет смысла (контент статьи закончился)
     */
    const CUT_CSS_CLASS = '';

    /**
     * Ссылка на RSS фид (XML)
     */
    const FEED_URL = 'https://krasnoturinsk.info/local/rss.php';

    /**
     *  Максимальная глубина для парсинга <div> тегов
     */
    const MAX_PARSE_DEPTH = 3;

    /**
     * Префикс для элементов списков (ul, ol и т.п.)
     * при преобразовании в текст
     * @see parseUl()
     */
    const UL_PREFIX = '-';

    /**
     * Кол-во новостей, которое необходимо парсить
     */
    const MAX_NEWS_COUNT = 10;

    /**
     * @return array
     * @throws Exception
     */
    public static function run(): array
    {
        $posts = [];
        $curl = Helper::getCurl();
        $rss = $curl->get(self::FEED_URL);

        $crawler = new Crawler($rss);

        $crawler->filter('channel item')->slice(0, self::MAX_NEWS_COUNT)->each(function ($node) use (&$curl, &$posts) {
            try {
                $enclosure = $node->filter('enclosure')->attr('url');
            } catch (Exception $exception) {
                $enclosure = null;
            }

            $newPost = new NewsPost(
                self::class,
                $node->filter('title')->text(),
                'description',
                self::stringToDateTime($node->filter('pubDate')->text()),
                $node->filter('link')->text(),
                $enclosure
            );

            /**
             * Предложения содержащиеся в описании (для последующей проверки при парсинга тела новости)
             */
            $descriptionSentences = explode('. ', html_entity_decode($newPost->description));

            /**
             * Получаем полный html новости
             */
            $newsContent = $curl->get($newPost->original);

            if (!empty($newsContent)) {
                /**
                 * Если время в rss указано как timestamp = 0, то берем из html новости
                 */
                if ($newPost->createDate->format('U') == 0) {
                    $createDate = (new Crawler($newsContent))->filter('.c-date')->text();
                    $newPost->createDate = DateTime::createFromFormat('d.m.Y H:i O', $createDate . ' +0800')
                        ->setTimezone(new DateTimeZone('UTC'));
                }

                /**
                 * Основное фото (всегда одно в начале статьи)
                 */
                $mainImage = (new Crawler($newsContent))->filter('.td-post-featured-image img');

                if ($mainImage->count()) {
                    if ($mainImage->attr('src')) {
                        $newPost->image = self::prepareImage($mainImage->attr('src'));
                    }
                }

                /**
                 * Подпись под основным фото
                 */
                $annotation = (new Crawler($newsContent))->filter('.widget-media__caption');

                if ($annotation->count() && !empty($annotation->text())) {
                    $newPost->addItem(
                        new NewsPostItem(
                            NewsPostItem::TYPE_TEXT,
                            $annotation->text(),
                            null,
                            null,
                            null,
                            null
                        )
                    );
                }

                $newsContent = (new Crawler($newsContent))->filter(self::BODY_CONTAINER_CSS_SELECTOR);

                /**
                 * Текст статьи, может содержать цитаты ( все полезное содержимое в тегах <p> )
                 * Не знаю нужно или нет, но сделал более универсально, с рекурсией
                 */
                $articleContent = $newsContent;

                $stopParsing = false;
                if ($articleContent->count()) {

                    $articleContent->each(function ($node) use ($newPost, &$stopParsing, $descriptionSentences) {
                        if ($stopParsing) {
                            return;
                        }
                        self::parseNode($node, $newPost, self::MAX_PARSE_DEPTH, $stopParsing, $descriptionSentences);
                    });
                }
            }

            $posts[] = $newPost;
        });

        return $posts;
    }

    protected static function parseNode(Crawler $node, NewsPost $newPost, int $maxDepth, bool &$stopParsing, $descriptionSentences = []): void
    {
        /**
         * Удаляем ненужный блок
         */
        if ($node->filter('.nd_ln_float')) {
            $node->filter('.nd_ln_float')->each(function (Crawler $crawler) {
                $node = $crawler->getNode(0);
                $node->parentNode->removeChild($node);
            });
        }

        /**
         * Пропускаем элемент, если элемент имеет определенный класс
         * @see EXCLUDE_CSS_CLASSES_PATTERN
         */
        if (self::EXCLUDE_CSS_CLASSES_PATTERN
            && strpos($node->attr('class'),self::EXCLUDE_CSS_CLASSES_PATTERN)) {
            return;
        }

        /**
         * Прекращаем парсить страницу, если дошли до конца статьи
         * (до определенного элемента с классом указанным в @see CUT_CSS_CLASS )
         *
         */
        if (self::CUT_CSS_CLASS && stristr($node->attr('class'), self::CUT_CSS_CLASS)) {
            $maxDepth = 0;
            $stopParsing = true;
        }

        /**
         * Ограничение максимальной глубины парсинга
         * @see MAX_PARSE_DEPTH
         */
        if (!$maxDepth) {
            return;
        }
        $maxDepth--;

        switch ($node->nodeName()) {
            case 'div':
            case 'span':
            case 'figure':
            case 'strong':
                $nodes = $node->children();
                if ($nodes->count()) {
                    $nodes->each(function ($node) use ($newPost, $maxDepth, &$stopParsing) {
                        self::parseNode($node, $newPost, $maxDepth, $stopParsing);
                    });
                }
                break;
            case 'p':
                self::parseParagraph($node, $newPost, $descriptionSentences);
                if ($nodes = $node->children()) {
                    $nodes->each(function ($node) use ($newPost, $maxDepth, &$stopParsing) {
                        self::parseNode($node, $newPost, $maxDepth, $stopParsing);
                    });
                }
                break;
            case self::QUOTE_TAG:
                self::parseParagraph($node, $newPost, $descriptionSentences);
                break;
            case 'img':
                self::parseImage($node, $newPost);
                break;
            case 'video':
                $videoId = self::extractYouTubeId($node->filter('source')->first()->attr('src'));
                self::addVideo($videoId, $newPost);
                break;
            case 'a':
                self::parseLink($node, $newPost);
                if ($nodes = $node->children()) {
                    $nodes->each(function ($node) use ($newPost, $maxDepth, &$stopParsing) {
                        self::parseNode($node, $newPost, $maxDepth, $stopParsing);
                    });
                }
                break;
            case 'iframe':
                $videoId = self::extractYouTubeId($node->attr('src'));
                self::addVideo($videoId, $newPost);
                break;
            case 'ul':
            case 'ol':
                self::parseUl($node, $newPost);
                break;
            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
                $headerLevel = $node->nodeName()[1];
                self::parseH($node, $newPost, $headerLevel);
                break;
        }

    }

    /**
     * Парсер для тегов <a>
     * @param Crawler $node
     * @param NewsPost $newPost
     */
    protected static function parseLink(Crawler $node, NewsPost $newPost): void
    {
        $href = $node->attr('href');

        if (strpos($href, self::MAIN_PAGE_URI) === false) {
            $href = self::MAIN_PAGE_URI . $href;
        }

        if (filter_var($href, FILTER_VALIDATE_URL)
            && !stristr($node->attr('class'), 'link-more')
            && strpos($href, '.jpg') === false) {
            $newPost->addItem(
                new NewsPostItem(
                    NewsPostItem::TYPE_LINK,
                    $node->text(),
                    null,
                    $href,
                    null,
                    null
                ));
        }
    }

    /**
     * Парсер для тегов <p>
     * Дополнительно сверяем содержимое тегов с описанием новости (по предложениям), дубли не добавляем
     * @param Crawler $node текущий элемент для парсинга
     * @param NewsPost $newPost объект новости
     * @param array $descriptionSentences массив предложений описания новости
     */
    private static function parseParagraph(Crawler $node, NewsPost $newPost, array $descriptionSentences): void
    {
        $nodeSentences = array_map(function ($item) {
            return !empty($item) ? trim($item, "  \t\n\r\0\x0B") : false;
        }, explode('. ', Helper::prepareString($node->text())));

        if ($newPost->description == 'description' || $newPost->description == '') {
            if (!empty($nodeSentences)) {
                $newPost->description = self::prepareDescription(implode('. ', $nodeSentences));
            }

            return;
        }

        $intersect = array_intersect($nodeSentences, $descriptionSentences);

        /**
         * Если в тексте есть хоть одно уникальное предложение ( по сравнению с описанием новости )
         */
        if (!empty($node->text()) && count($intersect) < count($nodeSentences)) {
            /**
             * Дополнительно проверяем, что оставшийся текст не является подстрокой описания
             */
            $text = implode('. ', array_diff($nodeSentences, $intersect));
            if (empty($text) || stristr($newPost->description, $text)) {
                return;
            }

            $type = NewsPostItem::TYPE_TEXT;
            if ($node->nodeName() == self::QUOTE_TAG) {
                $type = NewsPostItem::TYPE_QUOTE;
            }

            $newPost->addItem(
                new NewsPostItem(
                    $type,
                    $text,
                    null,
                    null,
                    null,
                    null
                )
            );
        }
    }

    /**
     * Парсер для тегов <img>
     * @param Crawler $node
     * @param NewsPost $newPost
     */
    protected static function parseImage(Crawler $node, NewsPost $newPost): void
    {
        $src = self::prepareImage($node->attr('src'));

        if (empty($newPost->image)) {
            $newPost->image = $src;
            return;
        }

        if ($src && $src != $newPost->image) {
            $newPost->addItem(
                new NewsPostItem(
                    NewsPostItem::TYPE_IMAGE,
                    null,
                    $src,
                    null,
                    null,
                    null
                ));
        }
    }

    /**
     * Кодирование киррилических симоволов в URL
     * Например из: https://misanec.ru/wp-content/uploads/2020/10/пожар3--840x1050.jpg
     * в: https://misanec.ru/wp-content/uploads/2020/10/%D0%BF%D0%BE%D0%B6%D0%B0%D1%803-840x1050.jpg
     *
     * @param string $imageUrl
     * @return string
     */
    private static function prepareImage(string $imageUrl): string
    {
        $imageUrl = str_replace(['background-image: url(', ')'], [''], $imageUrl);

        if (strpos($imageUrl, self::MAIN_PAGE_URI) === false) {
            $imageUrl = self::MAIN_PAGE_URI . $imageUrl;
        }

        return str_replace(['%3A', '%2F'], [':', '/'], rawurlencode($imageUrl));
    }

    private static function prepareDescription(string $description): string
    {
        $description = Helper::prepareString($description);
        $description = str_replace(
            [
                '[&#8230;]',
                '&#8220;',
                '&#8211;',
                '&#8212;',
                '&#8230;',
                ' появились сначала на SGPRESS - Самара, люди, события',
                '&#171;',
                '&#187;',
                '&nbsp;',
            ],
            [
                '',
                '"',
                '—',
                '—',
                '…',
                '',
                '«',
                '»',
                ' ',
            ], $description);

        return $description;
    }

}
