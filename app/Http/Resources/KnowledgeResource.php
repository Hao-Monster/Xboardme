<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Services\Plugin\HookManager;
use App\Services\PublicKnowledgeService;

class KnowledgeResource extends JsonResource
{
  /**
   * Transform the resource into an array.
   *
   * @return array<string, mixed>
   */
  public function toArray(Request $request): array
  {
    $data = [
      'id' => $this['id'],
      'category' => $this['category'],
      'title' => $this['title'],
      'body' => $this->when(isset($this['body']), $this['body']),
      'updated_at' => $this['updated_at'],
      'share_url' => app(PublicKnowledgeService::class)->shareUrlFor(
        (int) $this['id'],
        (string) $this['title']
      ),
    ];

    return HookManager::filter('user.knowledge.resource', $data, $request, $this);
  }
}
