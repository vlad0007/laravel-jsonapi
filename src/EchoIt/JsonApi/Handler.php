<?php namespace EchoIt\JsonApi;

use Illuminate\Support\Collection;

/**
 * Abstract class used to extend model API handlers from.
 *
 * @author Ronni Egeriis Persson <ronni@egeriis.me>
 */
abstract class Handler
{
    /**
     * Override this const in the extended to distinguish model handlers from each other.
     *
     * See under default error codes which bits are reserved.
     */
    const ERROR_SCOPE = 0;

    /**
     * Default error codes.
     */
    const ERROR_UNKNOWN_ID = 1;
    const ERROR_UNKNOWN_LINKED_RESOURCES = 2;
    const ERROR_NO_ID = 4;
    const ERROR_INVALID_ATTRS = 8;
    const ERROR_RESERVED_4 = 16;
    const ERROR_RESERVED_5 = 32;
    const ERROR_RESERVED_6 = 64;
    const ERROR_RESERVED_7 = 128;
    const ERROR_RESERVED_8 = 256;
    const ERROR_RESERVED_9 = 512;

    /**
     * Constructor.
     *
     * @param JsonApi\Request $request
     */
    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * Check whether a method is supported for a model.
     *
     * @param  string $method HTTP method
     * @return boolean
     */
    public function supportsMethod($method)
    {
        return method_exists($this, static::methodHandlerName($method));
    }

    /**
     * Fulfill the API request and return a response.
     *
     * @return JsonApi\Response
     */
    public function fulfillRequest()
    {
        $methodName = static::methodHandlerName($this->request->method);
        $models = $this->{$methodName}($this->request);

        if (is_null($models)) {
            throw new Exception(
                'Unknown ID',
                static::ERROR_SCOPE | static::ERROR_UNKNOWN_ID,
                404
            );
        }

        if ($models instanceof Response) {
            $response = $models;
        } else {
            $models->load($this->exposedRelationsFromRequest());
            $response = new Response($models);
            $response->linked = $this->getLinkedModels($models);
            $response->errors = $this->getNonBreakingErrors();
        }

        return $response;
    }

    /**
     * Returns which requested linked resources are available.
     *
     * @return array
     */
    protected function exposedRelationsFromRequest()
    {
        return array_intersect(static::$exposedRelations, $this->request->include);
    }

    /**
     * Returns which of the requested linked resources are not available.
     *
     * @return array
     */
    protected function unknownRelationsFromRequest()
    {
        return array_diff($this->request->include, static::$exposedRelations);
    }

    /**
     * Iterate through result set to fetch the requested linked resources.
     *
     * @param  Illuminate\Database\Eloquent\Collection|JsonApi\Model $models
     * @return array
     */
    protected function getLinkedModels($models)
    {
        $linked = [];
        $models = $models instanceof Collection ? $models : [$models];

        foreach ($models as $model) {
            foreach ($model->getRelations() as $key => $collection) {
                $l = (
                    array_key_exists($key, $linked)
                        ? $linked[$key]
                        : ($linked[$key] = new Collection)
                );

                foreach ($collection as $obj) {
                    // Check whether the object is already included in the response on it's ID
                    if (in_array($obj->id, $l->lists('id'))) continue;

                    $l->push($obj);
                }
            }
        }

        return $linked;
    }

    /**
     * Return errors which did not prevent the API from returning a result set.
     *
     * @return array
     */
    protected function getNonBreakingErrors()
    {
        $errors = [];

        $unknownRelations = $this->unknownRelationsFromRequest();
        if (count($unknownRelations) > 0) {
            $errors[] = [
                'code' => static::ERROR_UNKNOWN_LINKED_RESOURCES,
                'title' => 'Unknown linked resources requested',
                'description' => 'These linked resources are not available: ' . implode(', ', $unknownRelations)
            ];
        }

        return $errors;
    }

    /**
     * Convert HTTP method to it's handler method counterpart.
     *
     * @param  string $method HTTP method
     * @return string
     */
    protected static function methodHandlerName($method)
    {
        return 'handle' . ucfirst(strtolower($method));
    }
}