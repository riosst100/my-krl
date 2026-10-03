<?php

namespace App\Http\Requests;

use App\Services\FavoriteStationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFavoriteStationsRequest extends FormRequest
{
    public function rules(): array
    {
        $count = FavoriteStationService::REQUIRED;

        return [
            'stations' => ['required', 'array', "size:{$count}"],
            'stations.*' => ['required', 'string', 'distinct', Rule::exists('stations', 'code')->where('is_active', true)],
        ];
    }

    public function messages(): array
    {
        return [
            'stations.size' => 'Pilih tepat :size stasiun favorit.',
            'stations.*.distinct' => 'Kedua stasiun favorit harus berbeda.',
            'stations.*.exists' => 'Stasiun tidak ditemukan atau tidak aktif.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'stations' => array_map(fn ($code) => is_string($code) ? strtoupper(trim($code)) : $code, (array) $this->input('stations', [])),
        ]);
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_values($this->validated('stations'));
    }
}
