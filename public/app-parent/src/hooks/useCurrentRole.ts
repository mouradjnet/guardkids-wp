import { useQuery } from '@tanstack/react-query';
import { getMe, type CurrentRole, type Me } from '../api/me';

export type UseCurrentRoleResult = {
  isLoading: boolean;
  role: CurrentRole;
  name: string;
  email: string;
  isAdmin: boolean;
  isCollaborator: boolean;
};

export function useCurrentRole(): UseCurrentRoleResult {
  const query = useQuery({ queryKey: ['me'], queryFn: getMe });
  const data: Me = query.data ?? { role: null, email: '', name: '' };
  return {
    isLoading: query.isLoading,
    role: data.role,
    email: data.email,
    name: data.name,
    isAdmin: data.role === 'admin',
    isCollaborator: data.role === 'collaborator',
  };
}
