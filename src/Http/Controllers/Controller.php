<?php

namespace TCG\Voyager\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use TCG\Voyager\Exceptions\JsonInvalidException;
use TCG\Voyager\Facades\Bread as BreadFacade;
use TCG\Voyager\Facades\Voyager as VoyagerFacade;
use TCG\Voyager\Plugins\AuthenticationPlugin;
use TCG\Voyager\Plugins\AuthorizationPlugin;

abstract class Controller extends BaseController
{
    public function authorize($ability, $arguments = [])
    {
        return $this->getAuthorizationPlugin()->authorize($ability, $arguments);
    }

    public function getBread(Request $request)
    {
        $slug = explode('.', $request->route()->getName())[1];

        return BreadFacade::getBreadBySlug($slug);
    }

    protected function searchQuery(&$query, $layout, $filters, $global)
    {
        if ($global != '') {
            $columns = $layout->getSearchableColumns()->pluck('column')->filter(function ($column) {
                return !Str::contains($column, '.');
                // TODO: Also search for relationships?
            });
            $query->where(function ($query) use ($columns, $global) {
                $columns->each(function ($column) use (&$query, $global) {
                    $query->orWhere($column, 'LIKE', '%'.$global.'%');
                });
            });
        }

        foreach ($filters as $column => $filter) {
            // TODO: Search translatable
            if (Str::contains($column, '.')) {
                $relationship = Str::before($column, '.');
                $column = Str::after($column, '.');
                if (Str::contains($column, 'pivot.')) {
                    // TODO: Unfortunately we can't use wherePivot() here.
                } else {
                    $query = $query->whereHas($relationship, function ($query) use ($column, $filter) {
                        $query->where($column, 'like', '%'.$filter.'%');
                    });
                }
            } else {
                $formfield = $layout->formfields->where('column', $column)->first();
                if ($formfield) {
                    $query = $formfield->query($query, $column, $filter);
                }
            }
        }
    }

    protected function orderQuery(&$query, $bread, $layout, $column, $direction)
    {
        if ($layout->isFormfieldTranslatable($column)) {
            // TODO: Order by translatable
            $query = $query->orderBy($column, $direction);
        } else {
            $query = $query->orderBy($column, $direction);
        }
    }

    protected function loadAccessors(&$query, $bread)
    {
        if ($query instanceof \Illuminate\Database\Eloquent\Model) {
            $query->append($bread->getComputedProperties());
        } elseif ($query instanceof Collection) {
            $query->each(function ($item) use ($bread) {
                $item->append($bread->getComputedProperties());
            });
        }
    }

    // Manipulate data to be shown when browsing, showing or editing
    protected function prepareDataForEditing(Model $model, $bread, $layout): Model
    {
        return $this->prepareDataForBrowsing($model, $bread, $layout, 'edit');
    }

    protected function prepareDataForReading(Model $model, $bread, $layout): Model
    {
        return $this->prepareDataForBrowsing($model, $bread, $layout, 'show');
    }

    protected function prepareDataForBrowsing(Model $model, $bread, $layout, $method = 'browse'): Model
    {
        $layout->formfields->each(function ($formfield) use (&$model, $bread, $method) {
            $column = $formfield->column;
            $value = '';
            if (array_key_exists($formfield->column, $model->getAttributes())) {
                $value = $model->{$formfield->column};
            } elseif (Str::contains($column, '.')) {
                $rl_column = Str::after($column, '.');
                $rl_name = Str::before($column, '.');
                $model->with($rl_name);
                if ($model->{$rl_name} instanceof Collection) {
                    $value = $model->{$rl_name}->pluck($rl_column);
                } elseif ($model->{$rl_name}) {
                    $value = $model->{$rl_name}->{$rl_column};
                }
            }

            $new_value = $formfield->{$method}($value, $model);

            // Merge columns in $new_value back into the model
            foreach ($new_value as $key => $value) {
                $model->{$key} = $value;
            }
        });
        $model->primary = $model->getKey();

        return $model;
    }

    // Manipulate data to be stored in the database when updating
    protected function prepareDataForUpdating($data, Model $model, $bread, $layout): Model
    {
        return $this->prepareDataForStoring($data, $model, $bread, $layout, 'update');
    }

    // Manipulate data to be stored in the database when creating
    protected function prepareDataForStoring($data, Model $model, $bread, $layout, $method = 'store'): Model
    {
        $columns = VoyagerFacade::getColumns($model->getTable());
        $layout->formfields->each(function ($formfield) use ($data, &$model, $bread, $layout, $method, $columns) {
            $value = $data->get($formfield->column, null);
            $old = null;
            //if (array_key_exists($formfield->column, $model->getAttributes())) {
            $old = $model->{$formfield->column};
            //}
            $new_value = collect($formfield->{$method}($value, $old, $model, $data));
            $new_value->transform(function ($value, $column) use ($bread, $layout, $method) {
                if ($layout->isFormfieldTranslatable($column) && is_array($value)) {
                    // TODO: Check for casts here
                    $value = json_encode($value);
                }

                return $value;
            });

            // Merge columns in $new_value back into the model
            foreach ($new_value as $column => $value) {
                if (in_array($formfield->column, $columns)) {
                    $model->{$column} = $value;
                }
            }
        });

        return $model;
    }

    protected function getValidator($layout, $data)
    {
        $rules = [];
        $messages = [];

        $layout->formfields->each(function ($formfield) use (&$rules, &$messages, $layout) {
            $formfield_rules = [];
            collect($formfield->rules)->each(function ($rule_object) use ($formfield, &$formfield_rules, &$messages, $layout) {
                $formfield_rules[] = $rule_object->rule;
                $message_ident = $formfield->column.'.'.Str::before($rule_object->rule, ':');
                if ($layout->isFormfieldTranslatable($formfield->column)) {
                    $message_ident = $formfield->column.'.'.VoyagerFacade::getLocale().'.'.Str::before($rule_object->rule, ':');
                }
                $message = $rule_object->message;
                if (is_object($message)) {
                    $message = $message->{VoyagerFacade::getLocale()} ?? $message->{VoyagerFacade::getFallbackLocale()} ?? '';
                }

                $messages[$message_ident] = $message;
            });
            if ($layout->isFormfieldTranslatable($formfield->column)) {
                $rules[$formfield->column.'.'.VoyagerFacade::getLocale()] = $formfield_rules;
            } else {
                $rules[$formfield->column] = $formfield_rules;
            }
        });

        return Validator::make($data->toArray(), $rules, $messages);
    }

    protected function getJson(Request $request, $key = 'data')
    {
        $data = $request->get($key, '{}');
        $data = json_decode((string) $data, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new JsonInvalidException('Unable to parse response data: '.json_last_error());
        }

        return $data ?? [];
    }

    protected function getAuthorizationPlugin()
    {
        return VoyagerFacade::getPluginByType('authorization', AuthorizationPlugin::class);
    }

    protected function getAuthenticationPlugin()
    {
        return VoyagerFacade::getPluginByType('authentication', AuthenticationPlugin::class);
    }
}
