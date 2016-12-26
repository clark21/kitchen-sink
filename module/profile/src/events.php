<?php //-->
/**
 * This file is part of the Salaaap Project.
 * (c) 2016-2018 Openovate Labs
 *
 * Copyright and license information can be found at LICENSE.txt
 * distributed with this package.
 */

use Cradle\Module\Review\Service as ReviewService;

use Cradle\Module\Profile\Service as ProfileService;
use Cradle\Module\Profile\Validator as ProfileValidator;

use Aws\S3\S3Client;
use Aws\S3\PostObjectV4;

use Cradle\Http\Request;
use Cradle\Http\Response;

use Cradle\Module\Utility\File;

/**
 * Profile Add Experience (supporting job)
 *
 * @param Request $request
 * @param Response $response
 */
$cradle->on('profile-add-achievement', function ($request, $response) {
    //get data
    $data = $request->getStage();

    //this is what we need
    if (!isset(
        $data['profile_id'],
        $data['profile_achievements']['title'],
        $data['profile_achievements']['image']
    )
    ) {
        return;
    }

    //this/these will be used a lot
    $profileSql = ProfileService::get('sql');
    $profileRedis = ProfileService::get('redis');
    $profileElastic = ProfileService::get('elastic');

    $profile = $profileSql->get($data['profile_id']);

    $title = $data['profile_achievements']['title'];
    $image = $data['profile_achievements']['image'];

    //if it's already there
    if (isset($profile['profile_achievements'][$title])) {
        //no need to update
        return;
    }

    $profile['profile_achievements'][$title] = $image;

    $profileSql->update([
        'profile_id' => $profile['profile_id'],
        'profile_achievements' => json_encode($profile['profile_achievements'])
    ]);

    //update index
    $profileElastic->update($profile['profile_id']);

    //So this job is called in a good number of jobs some that caches data,
    //but if this job invalidates that same cache which de-purposes that logic.
    //So what we should do is build the again cache here

    $profileRedis->createDetail($profile['profile_id'], $profile);
    $profileRedis->createDetail($profile['profile_slug'], $profile);
});

/**
 * Profile Add Experience (supporting job)
 *
 * @param Request $request
 * @param Response $response
 */
$cradle->on('profile-add-experience', function ($request, $response) {
    //get data
    $data = $request->getStage();

    //this is what we need
    if (!isset($data['profile_id'], $data['profile_experience'])) {
        return;
    }

    //this/these will be used a lot
    $profileSql = ProfileService::get('sql');
    $profileRedis = ProfileService::get('redis');
    $profileElastic = ProfileService::get('elastic');

    //add view
    $profileSql->addExperience($data['profile_id'], $data['profile_experience']);

    //update index
    $profileElastic->update($data['profile_id']);

    //So this job is called in a good number of jobs some that caches data,
    //but if this job invalidates that same cache which de-purposes that logic.
    //So what we should do is build the again cache here

    $data = $profileSql->get($data['profile_id']);
    $profileRedis->createDetail($data['profile_id'], $data);
    $profileRedis->createDetail($data['profile_slug'], $data);
});

/**
 * Profile Create Job
 *
 * @param Request $request
 * @param Response $response
 */
$cradle->on('profile-create', function ($request, $response) {
    //get data
    $data = [];
    if ($request->hasStage()) {
        $data = $request->getStage();
    }

    //this/these will be used a lot
    $profileSql = ProfileService::get('sql');
    $profileRedis = ProfileService::get('redis');
    $profileElastic = ProfileService::get('elastic');

    //validate
    $errors = ProfileValidator::getCreateErrors($data);

    //if there are errors
    if (!empty($errors)) {
        return $response
            ->setError(true, 'Invalid Parameters')
            ->set('json', 'validation', $errors);
    }

    //if there is an image
    if ($request->hasStage('profile_image')) {
        //upload files
        //try cdn if enabled
        $this->trigger('profile-image-base64-cdn', $request, $response);
        //try being old school
        $this->trigger('profile-image-base64-upload', $request, $response);

        $data['profile_image'] = $request->getStage('profile_image');
    } else {
        //generate image
        $protocol = 'http';
        if ($request->getServer('SERVER_PORT') === 443) {
            $protocol = 'https';
        }

        $host = $protocol . '://' . $request->getServer('HTTP_HOST');

        $data['profile_image'] = $host . '/images/avatar/avatar-'
            . ((floor(rand() * 1000) % 11) + 1) . '.png';
    }

    //save profile to database
    $results = $profileSql->create($data);

    //index profile
    $profileElastic->create($results['profile_id']);

    //invalidate cache
    $profileRedis->removeSearch();

    //return response format
    $response->setError(false)->setResults($results);
});

/**
* Profile Detail Job
*
* @param Request $request
* @param Response $response
*/
$cradle->on('profile-detail', function ($request, $response) {
    //get data
    $data = [];
    if ($request->hasStage()) {
        $data = $request->getStage();
    }

    $id = null;
    if (isset($data['profile_id'])) {
        $id = $data['profile_id'];
    } else if (isset($data['profile_slug']) && $data['profile_slug']) {
        $id = $data['profile_slug'];
    }

    //we need an id
    if (!$id) {
        return $response->setError(true, 'Invalid ID');
    }

    //this/these will be used a lot
    $profileSql = ProfileService::get('sql');
    $profileRedis = ProfileService::get('redis');
    $profileElastic = ProfileService::get('elastic');

    $results = null;

    //if no flag
    if (!$request->hasGet('nocache')) {
        //get it from cache
        $results = $profileRedis->getDetail($id);
    }

    //if no results
    if (!$results) {
        //if no flag
        if (!$request->hasGet('noindex')) {
            //get it from index
            $results = $profileElastic->get($id);
        }

        //if no results
        if (!$results) {
            //get it from database
            $results = $profileSql->get($id);
        }

        if ($results) {
            //cache it from database or index
            $profileRedis->createDetail($id, $results);
        }
    }

    if (!$results) {
        return $response->setError(true, 'Not Found');
    }

    //if permission is provided
    $permission = $request->getStage('permission');
    if ($permission && $results['profile_id'] != $permission) {
        return $response->setError(true, 'Invalid Permissions');
    }

    $response->setError(false)->setResults($results);
});

/**
* File Base64 Upload Job (supporting job)
*
* @param Request $request
* @param Response $response
*/
$cradle->on('profile-image-base64-upload', function ($request, $response) {
    $data = $request->getStage('profile_image');

    //if not base 64
    if (strpos($data, ';base64,') === false) {
        //we don't need to convert
        return;
    }

    //first get the destination
    $destination = $this->package('global')->path('upload');

    //if not
    if (!is_dir($destination)) {
        //make one
        mkdir($destination);
    }

    //this is the root for file_link
    $protocol = 'http';
    if ($request->getServer('SERVER_PORT') === 443) {
        $protocol = 'https';
    }

    $host = $protocol . '://' . $request->getServer('HTTP_HOST');
    $extension = File::getExtensionFromData($data);

    $file = '/' . md5(uniqid()) . '.' . $extension;

    $path = $destination . $file;
    $link = $host . '/upload' . $file;

    //data:mime;base64,data
    $base64 = substr($data, strpos($data, ',') + 1);
    file_put_contents($path, base64_decode($base64));

    //now put it back
    $request->setStage('profile_image', $link);
});

/**
* Upload Base64 images to CDN (supporting job)
*
* @param Request $request
* @param Response $response
*/
$cradle->on('profile-image-base64-cdn', function ($request, $response) {
    //profile_image can be a link or base64
    $data = $request->getStage('profile_image');

    //if not base 64
    if (strpos($data, ';base64,') === false) {
        //we don't need to convert
        return;
    }

    $config = $this->package('global')->service('s3-main');

    //if there's no service
    if (!$config) {
        //we cannot continue
        return;
    }

    //if it's not configured
    if($config['token'] === '<AWS TOKEN>'
        || $config['secret'] === '<AWS SECRET>'
        || $config['bucket'] === '<S3 BUCKET>'
    )
    {
        return;
    }

    // load s3
    $s3 = S3Client::factory([
        'version' => 'latest',
        'region'  => $config['region'], //example ap-southeast-1
        'credentials' => array(
            'key'    => $config['token'],
            'secret' => $config['secret'],
        )
    ]);

    $mime = File::getMimeFromData($data);
    $extension = File::getExtensionFromData($data);
    $file = md5(uniqid()) . '.' . $extension;
    $base64 = substr($data, strpos($data, ',') + 1);
    $body = fopen('data://' . $mime . ';base64,' . $base64, 'r');

    $s3->putObject([
        'Bucket'         => $config['bucket'],
        'ACL'            => 'public-read',
        'ContentType'    => $mime,
        'Key'            => 'upload/' . $file,
        'Body'           => $body,
        'CacheControl'   => 'max-age=43200'
    ]);

    fclose($body);

    $link = $config['host'] . '/' . $config['bucket'] . '/upload/' . $file;
    $request->setStage('profile_image', $link);
});

/**
* Upload images to CDN from client (supporting job)
*
* @param Request $request
* @param Response $response
*/
$cradle->on('profile-image-client-cdn', function ($request, $response) {
    $config = $this->package('global')->service('s3-main');

    //if there's no service
    if (!$config) {
        //we cannot continue
        return;
    }

    //if it's not configured
    if($config['token'] === '<AWS TOKEN>'
        || $config['secret'] === '<AWS SECRET>'
        || $config['bucket'] === '<S3 BUCKET>'
    )
    {
        return;
    }

    // load s3
    $s3 = S3Client::factory([
        'version' => 'latest',
        'region'  => $config['region'], //example ap-southeast-1
        'credentials' => array(
            'key'    => $config['token'],
            'secret' => $config['secret'],
        )
    ]);

    $postObject = new PostObjectV4(
        $s3,
        $config['bucket'],
        [
            'acl' => 'public-read',
            'key' => 'upload/' . md5(uniqid())
        ],
        [
            ['acl' => 'public-read'],
            ['bucket' => $config['bucket']],
            ['starts-with', '$key', 'upload/']
        ],
        '+2 hours'
    );

    $response->setResults('cdn', [
        // Get attributes to set on an HTML form, e.g., action, method, enctype
        'form' => $postObject->getFormAttributes(),
        // Get form input fields. This will include anything set as a form input in
        // the constructor, the provided JSON policy, your AWS Access Key ID, and an
        // auth signature.
        'inputs' => $postObject->getFormInputs()
    ]);
});

/**
* Profile Remove Job
*
* @param Request $request
* @param Response $response
*/
$cradle->on('profile-remove', function ($request, $response) {
    //get the profile detail
    $this->trigger('profile-detail', $request, $response);

    //if there's an error
    if ($response->isError()) {
        return;
    }

    //get data
    $data = $response->getResults();

    //this/these will be used a lot
    $profileSql = ProfileService::get('sql');
    $profileRedis = ProfileService::get('redis');
    $profileElastic = ProfileService::get('elastic');

    //save to database
    $results = $profileSql->update([
        'profile_id' => $data['profile_id'],
        'profile_active' => 0
    ]);

    //remove from index
    $profileElastic->remove($id);

    //invalidate cache
    $profileRedis->removeDetail($data['profile_id']);
    $profileRedis->removeDetail($data['profile_slug']);
    $profileRedis->removeSearch();

    $response->setError(false)->setResults($results);
});

/**
* Profile Restore Job
*
* @param Request $request
* @param Response $response
*/
$cradle->on('profile-restore', function ($request, $response) {
    //get the profile detail
    $this->trigger('profile-detail', $request, $response);

    //if there's an error
    if ($response->isError()) {
        return;
    }

    //get data
    $data = $response->getResults();

    //this/these will be used a lot
    $profileSql = ProfileService::get('sql');
    $profileRedis = ProfileService::get('redis');
    $profileElastic = ProfileService::get('elastic');

    //save to database
    $results = $profileSql->update([
        'profile_id' => $data['profile_id'],
        'profile_active' => 1
    ]);

    //create index
    $profileElastic->create($id);

    //invalidate cache
    $profileRedis->removeSearch();

    $response->setError(false)->setResults($id);
});

/**
* Profile Search Job
*
* @param Request $request
* @param Response $response
*/
$cradle->on('profile-search', function ($request, $response) {
    //get data
    $data = [];
    if ($request->hasStage()) {
        $data = $request->getStage();
    }

    //this/these will be used a lot
    $profileSql = ProfileService::get('sql');
    $profileRedis = ProfileService::get('redis');
    $profileElastic = ProfileService::get('elastic');

    $results = false;

    //if no flag
    if (!$request->hasGet('nocache')) {
        //get it from cache
        $results = $profileRedis->getSearch($data);
    }

    //if no results
    if (!$results) {
        //if no flag
        if (!$request->hasGet('noindex')) {
            //get it from index
            $results = $profileElastic->search($data);
        }

        //if no results
        if (!$results) {
            //get it from database
            $results = $profileSql->search($data);
        }

        if ($results) {
            //cache it from database or index
            $profileRedis->createSearch($data, $results);
        }
    }

    //set response format
    $response->setError(false)->setResults($results);
});

/**
* Profile Update Job
*
* @param Request $request
* @param Response $response
*/
$cradle->on('profile-update', function ($request, $response) {
    //get the profile detail
    $this->trigger('profile-detail', $request, $response);

    //if there's an error
    if ($response->isError()) {
        return;
    }

    //get data
    $data = [];
    if ($request->hasStage()) {
        $data = $request->getStage();
    }

    //this/these will be used a lot
    $profileSql = ProfileService::get('sql');
    $profileRedis = ProfileService::get('redis');
    $profileElastic = ProfileService::get('elastic');

    //validate
    $errors = ProfileValidator::getUpdateErrors($data);

    //if there are errors
    if (!empty($errors)) {
        return $response
            ->setError(true, 'Invalid Parameters')
            ->set('json', 'validation', $errors);
    }

    //if there is an image
    if ($request->hasStage('profile_image')) {
        //upload files
        //try cdn if enabled
        $this->trigger('profile-image-base64-cdn', $request, $response);
        //try being old school
        $this->trigger('profile-image-base64-upload', $request, $response);

        $data['profile_image'] = $request->getStage('profile_image');
    }

    //save profile to database
    $results = $profileSql->update($data);

    //index profile
    $profileElastic->update($response->getResults('profile_id'));

    //invalidate cache
    $profileRedis->removeDetail($response->getResults('profile_id'));
    $profileRedis->removeDetail($response->getResuts('profile_slug'));
    $profileRedis->removeSearch();

    //return response format
    $response->setError(false)->setResults($results);
});

/**
 * Profile update rating (supporting job)
 *
 * @param Request $request
 * @param Response $response
 */
$cradle->on('profile-update-rating', function ($request, $response) {
    //this/these will be used a lot
    $reviewSql = ReviewService::get('sql');

    $commentId = $response->getResults('comment_id');
    $profile = $reviewSql->getProfile($commentId);

    $reviewRequest = new Request();
    $reviewRequest->load();
    $reviewResponse = new Response();
    $reviewResponse->load();

    $reviewRequest->setStage([
        'filter' => [
            'about.profile_id' => $profile['profile_id']
        ]
    ]);

    //we are doing it this way to take advantage of index and cache
    $this->trigger('review-search', $reviewRequest, $reviewResponse);

    $rows = $reviewResponse->getResults('rows');
    $total = 0;
    $count = 0;

    foreach ($rows as $row) {
        if (!$row['comment_rating']) {
            continue;
        }

        $total += $row['comment_rating'];
        $count++;
    }

    if (!$count) {
        return;
    }

    $profileRequest = new Request();
    $profileRequest->load();
    $profileResponse = new Response();
    $profileResponse->load();

    $profileRequest->setStage([
        'profile_id' => $profile['profile_id'],
        'profile_rating' => $total / $count
    ]);

    //we are doing it this way to take advantage of index and cache
    $this->trigger('profile-update', $profileRequest, $profileResponse);
});