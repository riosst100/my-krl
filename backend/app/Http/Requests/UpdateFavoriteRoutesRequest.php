<?php

namespace App\Http\Requests;

use App\Services\FavoriteRouteService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateFavoriteRoutesRequest extends FormRequest
{
    public function rules(): array
    {
        $active = Rule::exists('stations', 'code')->where('is_active', true);

        return [
            'routes' => ['required', 'array', 'min:'.FavoriteRouteService::MIN, 'max:'.FavoriteRouteService::MAX],
            'routes.*' => ['required', 'array'],
            'routes.*.from' => ['required', 'string', $active],
            'routes.*.to' => ['required', 'string', $active, 'different:routes.*.from'],
        ];
    }

    public function messages(): array
    {
        return [
            'routes.required' => 'Pilih minimal '.FavoriteRouteService::MIN.' rute favorit.',
            'routes.min' => 'Pilih minimal :min rute favorit.',
            'routes.max' => 'Maksimal :max rute favorit.',
            'routes.*.from.required' => 'Pilih stasiun asal.',
            'routes.*.to.required' => 'Pilih stasiun tujuan.',
            'routes.*.from.exists' => 'Stasiun tidak ditemukan atau tidak aktif.',
            'routes.*.to.exists' => 'Stasiun tidak ditemukan atau tidak aktif.',
            'routes.*.to.different' => 'Stasiun asal dan tujuan harus berbeda.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $upper = fn ($code) => is_string($code) ? strtoupper(trim($code)) : $code;

        $this->merge([
            'routes' => array_map(
                fn ($route) => is_array($route) ? [...$route, 'from' => $upper($route['from'] ?? null), 'to' => $upper($route['to'] ?? null)] : $route,
                (array) $this->input('routes', []),
            ),
        ]);
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $seen = [];
            foreach ((array) $this->input('routes', []) as $i => $route) {
                $key = ($route['from'] ?? '').'>'.($route['to'] ?? '');
                if (isset($seen[$key])) {
                    $validator->errors()->add("routes.{$i}.to", 'Rute ini sudah dipilih.');
                }
                $seen[$key] = true;
            }
        }];
    }

    /**
     * @return list<array{from: string, to: string}>
     */
    public function routes(): array
    {
        return array_map(fn (array $r) => ['from' => $r['from'], 'to' => $r['to']], array_values($this->validated('routes')));
    }
}
