<?php

namespace App\Controllers\Admin;

use App\Models\Page;
use App\Services\Socket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Psr\Http\Message\ResponseInterface;
use Vesp\Controllers\ModelController;

class Pages extends ModelController
{
    protected string $model = Page::class;
    protected string|array $scope = 'pages';
    private bool $isNew = false;

    protected function beforeCount(Builder $c): Builder
    {
        if ($query = trim($this->getProperty('query', ''))) {
            $c->where(static function (Builder $c) use ($query) {
                $c->where('title', 'LIKE', "%$query%");
                $c->orWhere('alias', 'LIKE', "%$query%");
            });
        }

        return $c;
    }

    protected function beforeSave(Model $record): ?ResponseInterface
    {
        $c = Page::query();

        /** @var Page $record */
        $content = $record->content;
        $content['blocks'] = !empty($content['blocks']) ? array_values($content['blocks']) : [];
        $record->content = $content;

        if ($this->isNew = !$record->exists) {
            if (!$record->rank) {
                $record->rank = Page::query()->count();
            }
        } else {
            $c->where('id', '!=', $record->id);
        }

        if ((clone $c)->where('alias', $record->alias)->count()) {
            return $this->failure('errors.page.alias_exists');
        }

        if ((clone $c)->where('title', $record->title)->count()) {
            return $this->failure('errors.page.title_exists');
        }

        return null;
    }

    protected function afterSave(Model $record): Model
    {
        /** @var Page $record */
        foreach (Page::query()->orderBy('rank')->orderByDesc('updated_at')->cursor() as $idx => $page) {
            $page->update(['rank' => $idx]);
        }

        $record->processUploadedFiles();

        if ($this->isNew) {
            Socket::send('page-create', $this->prepareRow($record));
        } else {
            Socket::send('page-update', $this->prepareRow($record));
        }

        return $record;
    }
}