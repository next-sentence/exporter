<?php

include './vendor/autoload.php';

$env = parse_ini_file('.env', false, INI_SCANNER_RAW);

$db = new \App\DbConfig($env['DB_NAME'],$env['DB_HOST'], $env['DB_USER'], $env['DB_PASSWORD']);

try {
    echo 'Starting to create migrations tables...' . PHP_EOL;

    $result = $db->getConnection()->exec(
        "DROP TABLE IF EXISTS migrations_posts, migrations_media, temp_categories, temp_authors, temp_tags, temp_news;
                  SET group_concat_max_len = 15000;

                  CREATE TEMPORARY TABLE temp_news
                    SELECT
                      news.*,
                      CAST(IF(SUBSTRING(content_rus,
                                        LOCATE('[NMGALLERY]', content_rus) + CHAR_LENGTH('[NMGALLERY]'),
                                        LOCATE('[/NMGALLERY]',
                                               content_rus,
                                               LOCATE('[NMGALLERY]', content_rus) + CHAR_LENGTH('[NMGALLERY]')) -
                                        (LOCATE('[NMGALLERY]', content_rus) + CHAR_LENGTH('[NMGALLERY]'))) != '',
                              SUBSTRING(content_rus,
                                        LOCATE('[NMGALLERY]', content_rus) + CHAR_LENGTH('[NMGALLERY]'),
                                        LOCATE('[/NMGALLERY]',
                                               content_rus,
                                               LOCATE('[NMGALLERY]', content_rus) + CHAR_LENGTH('[NMGALLERY]')) -
                                        (LOCATE('[NMGALLERY]', content_rus) + CHAR_LENGTH('[NMGALLERY]'))),
                              0)
                           AS DECIMAL) as header_id
                    FROM news;
        
                    CREATE TEMPORARY TABLE temp_categories
                        SELECT
                          news_id,
                          group_concat(title_rus) AS 'categories'
                        FROM categories
                          LEFT JOIN categories_news ON categories_news.category_id = categories.id
                        GROUP BY news_id;
                    
                    CREATE TEMPORARY TABLE temp_authors
                        SELECT
                          news_id,
                          group_concat(title_rus) AS 'authors'
                        FROM authors
                          LEFT JOIN authors_news ON authors_news.author_id = authors.id
                        GROUP BY news_id;
                    
                    CREATE TEMPORARY TABLE temp_tags
                        SELECT
                          picture_id,
                          GROUP_CONCAT(tag) AS 'picture_tags'
                        FROM picture_tags
                          LEFT JOIN picture_tags_pictures ON picture_tags.id = picture_tags_pictures.picture_tag_id
                        GROUP BY picture_id;
                    
                    CREATE TABLE migrations_posts
                      ENGINE = MYISAM
                        SELECT
                          temp_news.id as id,
                          'new' as status,
                          temp_news.title_rus as title_rus,
                          slug,
                          subtitle_rus,
                          content_rus,
                          published,
                          temp_news.created as created,
                          temp_news.modified as modified,
                          temp_news.header_id as header_id,
                          CONCAT('/pic/news/',
                                 news_photos.news_id,
                                 '/normal/',
                                 news_photos.filename) news_photo,
                          news_photos.author_rus as news_photos_author,
                          CONCAT(pictures.folder, pictures.img) as picture,
                          pictures.author as picture_author,
                          temp_tags.picture_tags as picture_tags,
                          temp_categories.categories as categories,
                          temp_authors.authors as authors
                    
                        FROM temp_news
                          left join news_photos on news_photos.news_id = temp_news.id
                          left join temp_categories on temp_categories.news_id = temp_news.id
                          left join temp_authors on temp_authors.news_id = temp_news.id
                          left join temp_tags on temp_tags.picture_id = temp_news.header_id
                          left join pictures on pictures.id = temp_news.header_id
                    
                        ORDER BY temp_news.id;
                  
                  CREATE TABLE migrations_media
                      ENGINE = MYISAM
                        SELECT
                          pictures.id as id,
                          'new' as status,
                          CONCAT(pictures.folder, pictures.img) as picture,
                          pictures.author as picture_author,
                          temp_tags.picture_tags as picture_tags
                    
                        FROM pictures
                          left join temp_tags on temp_tags.picture_id = pictures.id
                    
                        ORDER BY pictures.id;
                  ALTER TABLE migrations_posts CHANGE status status VARCHAR(32) DEFAULT NULL;
                  ALTER TABLE migrations_media CHANGE status status VARCHAR(32) DEFAULT NULL;
                        
                        "
    );
    echo 'Migrations tables successful created' . PHP_EOL;

} catch (\Exception $e) {
    $utils->getLogs($e->getMessage());
}
