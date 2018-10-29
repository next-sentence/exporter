<?php

namespace App;

class Utils
{
    /**
     * @var array
     */
    protected $categories = [];
    /**
     * @var array
     */
    protected $tags = [];
    /**
     * @var array
     */
    protected $authors = [];
    /**
     * @var DbConfig
     */
    private $db;
    /**
     * @var \WPAPI
     */
    private $api;
    /**
     * @var \WPAPI
     */
    private $acfApi;
    private $posts;
    private $users;
    private $nmHost;

    public function __construct(DbConfig $db, \WPAPI $api, $nmHost)
    {
        $this->db = $db;
        $this->api = $api;
        $this->posts = new \WPAPI_Posts($api);
        $this->users = new \WPAPI_Users($api);
        $this->nmHost = $nmHost;
    }

    public function init()
    {
        $users = $this->users->getAll();

        foreach ($users as $item) {
            $this->authors[$item->slug] = $item;
        }

        $response = $this->api->get('/categories');
        $categories = json_decode($response->body);

        foreach ($categories as $category) {
            $this->categories[strtolower($category->name)] = $category;
        }

        $response = $this->api->get('/tags');
        $tags = json_decode($response->body);


        foreach ($tags as $tag) {
            $this->tags[strtolower($tag->name)] = $tag;
        }
    }

    public function initAcf($acfUrl)
    {
        $this->acfApi = clone $this->api;
        $this->acfApi->base = $acfUrl;
    }

    /**
     * @return DbConfig
     */
    public function getDb()
    {
        return $this->db;
    }

    /**
     * @param $post
     */
    public function addPost($post)
    {
        try {
            $data = $this->extractPost($post);

            $result = $this->posts->create($data);

            $this->getLogs('Post Uploaded id:' . $result->id . PHP_EOL);

            $this->updateStatus('post', $post->id, 'done');
        } catch (\Exception $exception) {
            $this->getLogs('Post was not uploaded');

            $this->updateStatus('post', $post->id, 'failed');
        }
    }

    /**
     * @param $post
     */
    public function addCustomPost($post)
    {
        try {
            $data = $this->extractCard($post);
            $card = $this->createCard($data);

            $response = $this->acfApi->post(
                '/cards/' . $card->id,
                ['Content-Type' => 'application/json'],
                json_encode($this->extractCardItem($post))
            );

            $response->throw_for_status();

            $this->getLogs('Card Uploaded id:' . $card->id . PHP_EOL);
            $this->updateStatus('cards', $post->id, 'done');

        } catch (\Exception $exception) {
            $this->getLogs('Card was not uploaded');
            $this->updateStatus('cards', $post->id, 'failed');
        }
    }

    /**
     * @param $media
     */
    public function addMedia($media)
    {
        try {
            $result = $this->extractMedia($media);

            $this->getLogs('Media Uploaded id:' . $result . PHP_EOL);

            $this->updateStatus('media', $media->id, 'done');
        } catch (\Exception $exception) {
            $this->getLogs('Media was not uploaded');

            $this->updateStatus('media', $media->id, 'failed');
        }
    }

    /**
     * @param $post
     * @return array
     * @throws \Requests_Exception
     */
    protected function extractPost($post)
    {
        echo "Assembling post id: " . $post->id . PHP_EOL;

        $data = [
            'date' => $post->created,
            'date_gmt' => $post->created,
            'modified' => $post->modified,
            'modified_gmt' => $post->created,
            'slug' => $post->slug,
            'title' => $post->title_rus,
            'excerpt' => $post->subtitle_rus,
            'content' => $this->extractInlineImages($post),
            'status' => $post->published ? 'publish' : 'draft',
            'featured_media' => $this->extractMedia($post),
            'categories' => $this->extractCategories($post->categories),
            'tags' => $this->extractTags($post),
            'author' => $this->extractAuthor($post),
        ];

        if ($data['categories']) {
            $this->getLogs('Attached to: ' . implode(',', $data['categories']));
        } else {
            $this->getLogs('No category attached');
        }

        return $data;
    }

    /**
     * @param $post
     * @return array
     */
    protected function extractCard($post)
    {
        echo "Assembling card id: " . $post->id . PHP_EOL;

        $data = [
            'date' => $post->created,
            'date_gmt' => $post->created,
            'modified' => $post->modified,
            'modified_gmt' => $post->created,
//            'slug' => $post->slug,
            'title' => $post->title_rus,
            'excerpt' => $post->partner_url,
            'content' => '',
            'status' => $post->published ? 'publish' : 'draft',
            'featured_media' => $this->extractMedia($post),
//            'categories' => $this->extractCategories('cards'),
//            'parent' => '12'
//            'author' => $this->extractAuthor($post),
        ];

//        if ($data['categories']) {
//            $this->getLogs('Attached to: ' . implode(',', $data['categories']));
//        } else {
//            $this->getLogs('No category attached');
//        }

        return $data;
    }

    /**
     * @param $post
     * @return array
     */
    protected function extractCardItem($post)
    {
        $stmt = $this->getDb()->getConnection()->prepare("SELECT * FROM card_items WHERE card_id = :id ORDER BY position ASC ");
        $stmt->execute(['id' => $post->id]);

        $items = [];
        while ($row = $stmt->fetch(\PDO::FETCH_OBJ)){
            $items['fields']['cards'][] = [
                'card_title' => $row->title_rus,
                'card_content' => $row->content_rus,
            ];
        }

        return $items;
    }

    /**
     * @param $data
     * @return int|null
     */
    protected function extractMedia($data)
    {
        if (!empty($data->picture)) {
            $image = $this->extractFeaturedImage($data->picture);
        }

        if (!empty($data->news_photo)) {
            $image = $this->extractFeaturedImage($data->news_photo);
        }

        if (!empty($image)) {
            if (!empty($data->picture_tags)) {
                $image['description'] = $data->picture_tags;
            }

            if (!empty($data->picture_author)) {
                $image['caption'] = $data->picture_author;
            }

            if (!empty($data->news_photos_author)) {
                $image['caption'] = $data->news_photos_author;
            }

            try {
                $media = $this->createMedia($image);
                $this->getLogs('Feature Image uploaded - ' . $image['name']);
            } catch (\Exception $exception) {
                $this->getLogs('Featured Image not found');
            }
        } else {
            $this->getLogs('Featured Image not found');
        }

        return empty($media) ? null : $media->id;
    }

    /**
     * @param $categories
     * @return array
     */
    public function extractCategories($categories)
    {
        $ids = [];

        if (!$categories) {
            return [];
        }

        $categories = explode(',', $categories);

        foreach ($categories as $category) {
            if (isset($this->categories[strtolower($category)])) {
                $ids[] = $this->categories[strtolower($category)]->id;
            } else {
                $data = ['name' => $category, 'slug' => $this->slugify($category)];
                $response = $this->api->post('/categories', [], $data);
                $result = json_decode($response->body);

                if ($response->success) {
                    $this->categories[strtolower($category)] = $result;
                    $ids[] = $result->id;
                }
            }
        }

        return $ids;
    }

    protected function extractAuthor($post)
    {
        $data = explode(',', $post->authors);
        $author = array_shift($data);

        //default author
        if (empty($author)) {
            $author = 'newsmaker';
        } else {
            $author = $this->slugify($author);
        }

        if (isset($this->authors[$author])) {
            return $this->authors[$author]->id;
        }

        if ($user = $this->createUser($author)) {
            return $user->id;
        }

        return null;
    }

    protected function extractTags($post)
    {
        $ids = [];

        $tags = explode(',', $post->authors);
        $author = array_shift($tags);

        foreach ($tags as $tag) {
            if (isset($this->tags[strtolower($tag)])) {
                $ids[] = $this->tags[strtolower($tag)]->id;
            } else {
                $data = ['name' => $tag, 'slug' => $this->slugify($tag)];
                $response = $this->api->post('/tags', [], $data);
                $result = json_decode($response->body);

                if ($response->success) {
                    $this->tags[strtolower($tag)] = $result;
                    $ids[] = $result->id;
                }
            }
        }

        return $ids;
    }

    protected function extractFeaturedImage($image)
    {
        $buff = explode('/', $image);
        $name = end($buff);

//        $image = filter_var($image, FILTER_SANITIZE_URL);

        // Check if it's a uri or url
        if (!filter_var($image, FILTER_VALIDATE_URL)) {
            $image = $this->nmHost . $image;
        }

        $image = str_replace(' ', '%20', $image);
        $image = str_replace('../', '', $image);

        if (!$file = file_get_contents($image)) {
            return [];
        }

        return [
            'file' => $file,
            'name' => $name,
        ];
    }


    /**
     * @param $post
     * @return string
     * @throws \Requests_Exception
     */
    protected function extractInlineImages($post)
    {
        $doc = new \DOMDocument();
        $html = $post->content_rus;
        $html = mb_convert_encoding($html, 'HTML-ENTITIES', "UTF-8");

        libxml_use_internal_errors(true);

        $doc->loadHTML($html);

        libxml_use_internal_errors(false);

        $imagesSrc = $doc->getElementsByTagName('img');
        if (!empty($imagesSrc)) {
            foreach ($imagesSrc as $imgSrc) {
                $this->formatImgTags($post, $imgSrc);
            }
        }

        $images = $doc->getElementsByTagName('object');

        if (!empty($images)) {
            $child = $images->item(0);
            if (is_object($child)) {
                $child->parentNode->removeChild($child);
            }

            foreach ($images as $img) {
                $this->formatNMTags($post, $doc, $img);
            }
        }

        return $doc->saveHTML();
    }

    /**
     * @param $post
     * @param $doc
     * @param $img
     * @throws \Requests_Exception
     */
    protected function formatNMTags($post, $doc, $img)
    {
        $content = $img->textContent;

        //Get img ID
        $imgID = explode('=', $content);
        $imgID = explode('[NMGALLERY]', $imgID[0]);
        $imgID = (int)$imgID[1];

        //Get full img path
        $picture = $this->getImgById($imgID);

        //Get image by path from server and send to wordpress API
        $image = $this->extractFeaturedImage($picture->path);

        if ($image) {
            //	System log
            $this->getLogs('Inline Image (NMGallery tag) uploaded - ' . $image['name']);

            if (!empty($post->picture_tags)) {
                $image['description'] = $post->picture_tags;
            }

            $media = $this->createMedia($image);

            if ($media) {
                //Create the image element with reference
                $node = $doc->createElement('img');
                $attr = $doc->createAttribute('src');
                $attr->value = $media->source_url;
                $node->appendChild($attr);
                $img->textContent = '';
                $img->appendChild($node);
            }
        }
    }

    /**
     * @param $post
     * @param $imgSrc
     */
    protected function formatImgTags($post, $imgSrc)
    {
        $content = $imgSrc->getAttribute('src');

        //Get image by path from server and send to wordpress API
        $image = $this->extractFeaturedImage($content);

        if ($image) {
            //	System log
            $this->getLogs('Inline Image (img tag) uploaded - ' . $image['name']);

            if (!empty($post->picture_tags)) {
                $image['description'] = $post->picture_tags;
            }

            $result = $this->api->post(\WPAPI::ROUTE_MEDIA, $this->getImgHeaders($image['name']), $image);

            if ($result->success) {
                //  Assign new url to img tag
                $imgSrc->setAttribute('src', $result->source_url);
            }
        }
    }

    /**
     * @param $id
     * @return array
     */
    protected function getImgById($id)
    {
        $stmt = $this->db->getConnection()->prepare('SELECT CONCAT(folder, img) path, author FROM pictures WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch(\PDO::FETCH_OBJ);
    }

    protected function getImgHeaders($name)
    {
        return [
            'Cache-Control' => 'no-cache',
            "Content-Disposition" => "attachment; filename={$name}",
            'Content-Type' => 'image/jpg'
        ];
    }

    protected function printData($post)
    {
        echo '<pre>';
        var_dump($post);
        echo '</pre>';
        exit();
    }

    public function getLogs($message)
    {
        echo date("Y-m-d H:i:s") . '-> ' . $message . PHP_EOL;
    }

    protected function slugify($string)
    {
        $str = explode(' ', $string);

        if (count($str) === 1) {
            $string = $str[0];
        } else {
            $string = $str[0] . ' ' . $str[1];
        }

        $string = transliterator_transliterate("Any-Latin; NFD; [:Nonspacing Mark:] Remove; NFC; [:Punctuation:] Remove; Lower();", $string);
        $string = str_replace('ʹ', '', $string);
        $string = preg_replace('/[-\s]+/', '.', $string);

        return trim($string, ".");
    }

    /**
     * @param $image
     * @return null|\WPAPI_Attachment
     * @throws \Requests_Exception
     */
    protected function createMedia($image)
    {
        $response = $this->api->post(\WPAPI::ROUTE_MEDIA, $this->getImgHeaders($image['name']), $image['file']);

        unset($image['name'], $image['file']);

        if ($response->success) {
            $media = json_decode($response->body);

            if ($image) {
                $response = $this->api->post(\WPAPI::ROUTE_MEDIA . '/' . $media->id, [], $image);
            }
            return $media;
        }

        return null;
    }

    /**
     * @param $author
     * @return null|\WPAPI_User
     */
    protected function createUser($author)
    {
        $data = [
            'username' => $author,
            'email' => $author . '@newsmaker.md',
            'slug' => $author,
            'password' => substr(md5($author), 0, 8),
            'roles' => ['administrator'],
        ];

        try {
            $result = $this->users->create($data);
            $this->authors[$author] = $result;

            return $result;

        } catch (\Exception $exception) {
            return null;
        }
    }

    /**
     * @param $data
     * @return \WPAPI_Post
     * @throws \Requests_Exception
     * @throws \Requests_Exception_HTTP
     */
    public function createCard($data)
    {
        $data = json_encode($data);
        $headers = ['Content-Type' => 'application/json'];
        $response = $this->api->post('/cards', $headers, $data);
        $response->throw_for_status();

        $data = json_decode($response->body, true);
        $has_error = ( function_exists('json_last_error') && json_last_error() !== JSON_ERROR_NONE );
        if ( ( ! $has_error && $data === null ) || $has_error ) {
            throw new \Exception($response->body);
        }
        return new \WPAPI_Post($this->api, $data);
    }

    /**
     * @param $table
     * @param $id
     * @param $status
     */
    protected function updateStatus($table, $id, $status)
    {
        $stmt = $this->getDb()->getConnection()->prepare("UPDATE migrations_{$table} SET status = :status where id = :id");
        $stmt->execute(['status' => $status, 'id' => $id]);
    }
}
