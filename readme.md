## Usage

1. Install dependencies
    
    ```
    $ php composer.phar install
    ```

2. Create your .env file from .env.dist and edit it

3. Run script to create temp post table
    ```
    $ php sql.php
    ```
    
4. Run post migration script
    
    ```
    $ php post.php
    ```    
    
5. Run media migration script
    
    ```
    $ php media.php
    ```
    
6. Run cards migration script (First make sure that you have installed [acf-to-rest-api](https://github.com/airesvsg/acf-to-rest-api) plugin)
    
    ```
    $ php card.php
    ```