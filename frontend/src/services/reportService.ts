import { api } from '@/lib/api'
import { downloadFile } from '@/lib/download'
import type { ApiResponse } from '@/types/api'
import type { ReportOptions, RpcppeReportParams } from '@/types/report'

export const reportService = {
  options: (officeId?: number) =>
    api
      .get<ApiResponse<ReportOptions>>('/reports/options', {
        params: officeId ? { office_id: officeId } : {},
      })
      .then((r) => r.data.data),
  downloadRpcppe: (officeId: number, params: RpcppeReportParams) => downloadFile(`/reports/rpcppe/${officeId}`, params, 'RPCPPE.xlsx'),
}
