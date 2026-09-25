@php($fieldSnippets = $snippets->get($field, collect()))
@if($fieldSnippets->isNotEmpty())
    <div class="dropdown snippet-menu">
        <button class="btn btn-xs btn-outline-secondary dropdown-toggle" type="button" data-toggle="dropdown">
            <i class="fas fa-list mr-1"></i>Insert snippet
        </button>
        <div class="dropdown-menu shadow-sm border-0">
            @foreach($fieldSnippets as $snippet)
                <button type="button" class="dropdown-item small" wire:click="applySnippet('{{ $field }}', {{ $snippet->id }})">
                    {{ $snippet->title }}
                </button>
            @endforeach
        </div>
    </div>
@endif
