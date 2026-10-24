import { t } from '../../i18n';
import { QueueWorkspace } from '../QueueWorkspace';
import { WorkspaceHeader, type WorkspaceProps } from '../readWorkspace';

export function ManagementWorkspace({projectId,scopeGeneration,locale='uz'}:WorkspaceProps) {
  return <section><WorkspaceHeader locale={locale} title={t(locale,'management')} projectId={projectId}/><QueueWorkspace projectId={projectId} scopeGeneration={scopeGeneration} locale={locale} domain="management"/></section>;
}
