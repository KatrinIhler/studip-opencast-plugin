<?php
/**
 * This class manage a bunch of jobs
 *
 * @author          Jan-Frederik Leissner <jleissner@uos.de>
 * @copyright   (c) Authors
 * @version         1.0 (11:19)
 */

class OCJobManager
{
    public static $BASE_PATH = '/tmp/opencast';
    public static $CACHE_SUCCESS = 7 * 24 * 60 * 60;  // 7 days
    public static $CACHE_FAILURE = 14 * 24 * 60 * 60; // 14 days

    /**
     * Get the path to the job with a specific id
     *
     * @param $job_id string a unique job id
     *
     * @return string
     */
    public static function job_path($job_id)
    {
        return static::$BASE_PATH . '/' . $job_id;
    }

    /**
     * @param $job_id string a unique job id
     *
     * @return bool true if the job exist
     */
    public static function job_exist($job_id)
    {
        return file_exists(static::job_path($job_id));
    }

    /**
     * Creates a job of a pile of arguments
     * @param $job_id
     * @param $course_id
     * @param $series_id
     * @param $flavor
     * @param $title
     * @param $creator
     * @param $record_date
     * @param $start_hour
     * @param $start_minute
     * @param $contributor
     * @param $subject
     * @param $language
     * @param $description
     * @param $file_size
     * @param $file_name
     * @param $file_type
     */
    public static function create_job(
        $job_id, $course_id, $series_id, $flavor,
        $title, $creator, $record_date, $start_hour, $start_minute, $contributor, $subject, $language, $description,
        $file_size, $file_name, $file_type)
    {
        $location = new OCJobLocation($job_id);
        $location->create();
        $job_data = new OCJsonFile($location->path() . '/job_data.json');
        $opencast_data = new OCJsonFile($location->path() . '/opencast_info.json');
        $job_data['id_list'] = array('job' => $job_id, 'course' => $course_id, 'series' => $series_id);
        $job_data['file'] = array('name' => $file_name, 'size' => $file_size, 'type' => $file_type);
        $job_data['info'] = array('title' => $title, 'creator' => $creator, 'record_date' => $record_date, 'start' => array('h' => $start_hour, 'm' => $start_minute), 'contributor' => $contributor, 'subject' => $subject, 'language' => $language, 'description' => $description);
        $job_data['creation_timestamp'] = time();
        $opencast_data['media_package'] = 'NOT GENERATED';
        $opencast_data['opencast_job_id'] = 'NOT GENERATED';
        $opencast_data['flavor'] = $flavor;
    }

    /**
     * @param $range string something like 'x-y/z' or '0'
     *
     * @return int
     */
    public static function calculate_chunk_number_from_range($range)
    {
        $pattern = '/(\d*)-\d*\/\d*/';
        $result = preg_match($pattern, $range, $matches);
        if ($result) {
            return $matches[1] / OC_UPLOAD_CHUNK_SIZE;
        }

        return 0;
    }

    /**
     * The path to a chunk-file with a specific number
     *
     * @param $job_id
     * @param $chunk_number
     *
     * @return string the path
     */
    public static function chunk_path($job_id, $chunk_number)
    {
        return static::job_path($job_id) . '/chunk_' . $chunk_number . '.part';
    }

    /**
     * Is the matterhorn service available?
     * @return bool true if it is
     */
    public static function matterhorn_service_available()
    {
        $configuration = OCEndpointModel::getBaseServerConf(1);
        $target = str_replace(array('http://','https://'),'',$configuration['service_url']);
        $socket = @fsockopen($target, 80, $err_number, $err_message, 1);

        if ($socket === FALSE) {
            return FALSE;
        }
        fclose($socket);

        return TRUE;
    }

    /**
     * For the lazy ones or the upload-controller
     * @return OCJob
     */
    public static function from_request()
    {
        $job_id = Request::get('uuid');
        if (!OCJobManager::job_exist($job_id)) {
            OCJobManager::create_job(
                $job_id,
                Request::get('cid'),
                Request::get('series_id'),
                'presenter/source',
                Request::get('title'),
                Request::get('creator'),
                Request::get('recordDate'),
                Request::get('startTimeHour'),
                Request::get('startTimeMin'),
                Request::get('contributor'),
                Request::get('subject'),
                Request::get('language'),
                Request::get('description'),
                Request::get('total_file_size'),
                Request::get('file_name'),
                $_FILES['video']['type']
            );
        }

        return new OCJob($job_id);
    }

    /**
     * Removes old jobs and their file-structure
     */
    public static function cleanup()
    {
        $job_ids = static::existent_jobs();
        foreach ($job_ids as $job_id) {
            $job = new OCJob($job_id);
            $minimum_success_time = time() - static::$CACHE_SUCCESS;
            $minimum_failure_time = time() - static::$CACHE_FAILURE;
            if (($job->both_uploads_succeeded() && $job->created_at_time() < $minimum_success_time) ||
                (!$job->both_uploads_succeeded() && $job->created_at_time() < $minimum_failure_time)
            ) {
                $job->clear_files();
            }
        }
    }

    /**
     * @return array list of existend job ids
     */
    public static function existent_jobs()
    {
        return array_diff(scandir(static::$BASE_PATH), array('.', '..'));
    }

    /**
     * Try to reupload old jobs
     */
    public static function try_reupload_old_jobs(){
        $job_ids = static::existent_jobs();
        foreach ($job_ids as $job_id) {
            $job = new OCJob($job_id);
            echo "Versuche Upload von '".$job_id."'...";
            $job->try_upload_to_opencast();
            echo "Beende Upload von '".$job_id."'...";
        }
    }
}