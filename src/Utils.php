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
    private $posts;
    private $users;

    public function __construct(DbConfig $db, \WPAPI $api)
    {
        $this->db = $db;
        $this->api = $api;
        $this->posts = new \WPAPI_Posts($api);
        $this->users = new \WPAPI_Users($api);

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

            $this->updatePostStatus('done');
        } catch (\Exception $exception) {
            $this->getLogs('Post was not uploaded');

            $this->updatePostStatus('failed');
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

            $this->updateMediaStatus('done');
        } catch (\Exception $exception) {
            $this->getLogs('Media was not uploaded');

            $this->updateMediaStatus('failed');
        }
    }

    /**
     * @param $post
     * @return array
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
            'categories' => $this->extractCategories($post),
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

    public function extractCategories($post)
    {
        $ids = [];

        $categories = $post->categories;

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

        $image = filter_var($image, FILTER_SANITIZE_URL);

        // Check if it's a uri or url
        if (!filter_var($image, FILTER_VALIDATE_URL)) {
            $image = "http://old.newsmaker.md/{$image}";
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
     * @param $status
     */
    protected function updatePostStatus($status)
    {
        $stmt = $this->getDb()->getConnection()->prepare("UPDATE migrations_posts SET status = ?");
        $stmt->execute([$status]);
    }
    /**
     * @param $status
     */
    protected function updateMediaStatus($status)
    {
        $stmt = $this->getDb()->getConnection()->prepare("UPDATE migrations_media SET status = ?");
        $stmt->execute([$status]);
    }
}
