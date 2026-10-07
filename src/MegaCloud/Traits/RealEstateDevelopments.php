<?php

namespace Nave\IssSatellite\MegaCloud\Traits;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;

trait RealEstateDevelopments
{
    public function getRealEstateDevelopments(array $query = []): Collection
    {
        return $this->get('/globalestruturas/Empreendimentos', $query)->collect();
    }

    public function getRealEstateDevelopmentBlocks(string $realEstateDevelopmentId): Collection
    {
        return $this->get("/globalestruturas/Empreendimentos/$realEstateDevelopmentId/Blocos")->collect();
    }

    public function getRealEstateDevelopmentUnitsByBlock(string $realEstateDevelopmentId, string $blockId): Collection
    {
        return $this->get("/globalestruturas/Empreendimentos/$realEstateDevelopmentId/Blocos/$blockId/Unidades")->collect();
    }

    public function getRealEstateDevelopmentsWithBlocksAndUnits(array $query = []): Collection
    {
        return $this->getRealEstateDevelopments($query)->map(function (array $realEstateDevelopment) {
            return collect([
                'id'           => $realEstateDevelopment['id'],
                'codigo'       => $realEstateDevelopment['codigo'],
                'codigoFilial' => $realEstateDevelopment['codigoFilial'],
                'nome'         => $realEstateDevelopment['nome'],
                'blocks'       => $this->getRealEstateDevelopmentBlocks($realEstateDevelopment['id'])->map(function (array $block) use ($realEstateDevelopment) {
                    return collect([
                        'id'           => $block['id'],
                        'codigo'       => $block['codigo'],
                        'codigoFilial' => $block['codigoFilial'],
                        'nome'         => $block['nome'],
                        'units'        => $this->getRealEstateDevelopmentUnitsByBlock($realEstateDevelopment['id'], $block['id'])->map(function (array $unit) use ($block, $realEstateDevelopment) {
                            return collect([
                                'id'                      => $unit['id'],
                                'realEstateDevelopmentId' => $realEstateDevelopment['id'],
                                'blockId'                 => $block['id'],
                                'codigo'                  => $unit['codigo'],
                                'codigoExterno'           => $unit['codigoExterno'],
                                'nome'                    => $unit['nome'],
                                'status'                  => $unit['status'],
                            ]);
                        }),
                    ]);
                }),
            ]);
        });
    }

    /**
     * Busca um empreendimento usando filtros.
     *
     * Exemplo: para buscar um empreendimento pelo CNPJ utilize o filtro ['cnpjFilial' => '99.999.999/9999-99'].
     *
     * @param array $filters
     * @param bool $includeUnits
     * @return Collection
     *
     * @throws RequestException|ConnectionException
     */
    public function getRealEstateDevelopment(array $filters = [], bool $includeUnits = false): Collection
    {
        // Essas chaves precisam ser em float porque o mega devolve essas informações como float
        $keysToConvertToFloat = [
            'codigo',
            'codigoFilial',
        ];

        foreach ($keysToConvertToFloat as $key) {
            if (array_key_exists($key, $filters)) {
                $filters[$key] = (float) $filters[$key];
            }
        }

        return $this->getRealEstateDevelopments()
            ->filter(function (array $item) use ($filters) {
                return collect($filters)->every(function (mixed $value, string $key) use ($item) {
                    return data_get($item, $key) === $value;
                });
            })
            ->when($includeUnits, function (Collection $realEstateDevelopment) {
                return $realEstateDevelopment->map(function (array $item) {
                    $item['blocks'] = $this->getRealEstateDevelopmentBlocks($item['id'])->map(function (array $block) use ($item) {
                        $block['realEstateDevelopmentCode'] = $item['codigo'];
                        $block['units'] = $this->getRealEstateDevelopmentUnitsByBlock($item['id'], $block['id'])->map(function (array $unit) use ($item, $block) {
                            $unit['realEstateDevelopmentCode'] = $item['codigo'];
                            $unit['blockCode'] = $block['codigo'];

                            return $unit;
                        });

                        return $block;
                    });

                    return $item;
                });
            });
    }

    public function getAllRealEstateDevelopmentUnits(array $query = [], int $flatten = 2): Collection
    {
        return $this->getRealEstateDevelopmentsWithBlocksAndUnits($query)
            ->pluck('blocks.*.units')
            ->flatten($flatten);
    }
}
