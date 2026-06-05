import { apiFetch } from './client';
import type { ApprovalRequest } from './types';

export type ListRequestsFilter = 'pending' | 'approved' | 'denied' | 'all';

export function listRequests(status: ListRequestsFilter = 'pending'): Promise<ApprovalRequest[]> {
  return apiFetch<ApprovalRequest[]>(`/requests?status=${status}`);
}

export function approveRequest(id: number): Promise<ApprovalRequest> {
  return apiFetch<ApprovalRequest>(`/requests/${id}/approve`, { method: 'POST' });
}

export function denyRequest(id: number): Promise<ApprovalRequest> {
  return apiFetch<ApprovalRequest>(`/requests/${id}/deny`, { method: 'POST' });
}
