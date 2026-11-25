@php
    $transitions = array_filter($record->status->editorialTransitions(), fn ($target) => $actor->hasPermission($type->permission($record->status->action($target))));
    $canDelete = $record->status === \App\Support\ContentStatus::Draft && $record->published_at === null && $actor->hasPermission($type->permission('delete'));
    $canDuplicate = $type === \App\Support\TravelGoType::Route && $actor->hasPermission($type->permission('create')) && $actor->canBrowseTravelGo($type);
@endphp
@if ($transitions !== [] || $canDelete || $canDuplicate)
    <section class="card">
        <h2>Управление записью</h2>
        @if ($warnings !== [] && $transitions !== [])
            <ul class="editor-links catalog-meta">
                @foreach ($warnings as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        @endif
        @if ($transitions !== [])
            <form class="fields" method="post" action="{{ route('admin.travelgo.status', [$type, $record->publicKey()]) }}" data-status-form>
                @csrf
                <div class="editor-grid">
                    <label>Новый статус
                        <select name="to" required>
                            @foreach ($transitions as $target)
                                <option value="{{ $target->value }}" @selected(old('to') === $target->value)>{{ $target->label() }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label data-publish-time>Дата публикации, UTC<input type="datetime-local" name="publishAt" value="{{ old('publishAt') }}"></label>
                    <label>Причина<input name="reason" value="{{ old('reason') }}" maxlength="1000"></label>
                </div>
                @if ($type === \App\Support\TravelGoType::Place && $actor->hasPermission(\App\Support\TravelGoType::Route->permission('publish')))
                    <label class="check-label"><input type="checkbox" name="force" value="1">При скрытии места также скрыть связанные маршруты</label>
                @endif
                <button type="submit" class="primary">Изменить статус</button>
            </form>
        @endif
        <div class="catalog-actions">
            @if ($canDuplicate)
                <form method="post" action="{{ route('admin.travelgo.duplicate', $record->id) }}">
                    @csrf
                    <button type="submit">Дублировать в черновик</button>
                </form>
            @endif
            @if ($canDelete)
                <form method="post" action="{{ route('admin.travelgo.destroy', [$type, $record->publicKey()]) }}" data-confirm-delete="Удалить этот черновик?">
                    @csrf
                    @method('DELETE')
                    <button class="danger-button" type="submit">Удалить черновик</button>
                </form>
            @endif
        </div>
    </section>
@endif
