import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { Features, Parametres } from '@/types';

const DEFAULTS: Features = { relances: true, effets: false, crm: false };

/**
 * État des modules activables de l'entreprise. Chargé une fois puis mis en
 * cache ; la page Paramètres invalide la clé ['parametres'] après un toggle.
 */
export function useFeatures(): { features: Features; isLoading: boolean } {
    const { data, isLoading } = useQuery({
        queryKey: ['parametres'],
        queryFn: async () => {
            const { data } = await api.get<{ data: Parametres }>('/parametres');
            return data.data;
        },
        staleTime: 5 * 60 * 1000,
    });

    return { features: data?.features ?? DEFAULTS, isLoading };
}
