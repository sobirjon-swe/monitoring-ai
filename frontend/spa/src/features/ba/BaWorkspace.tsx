import { t } from '../../i18n';
import { QueueWorkspace } from '../QueueWorkspace';
import { WorkspaceHeader, type WorkspaceProps } from '../readWorkspace';

export function BaWorkspace({projectId,scopeGeneration,locale='uz',canWrite=false}:WorkspaceProps & {canWrite?:boolean}) {
  return <section><WorkspaceHeader locale={locale} title={t(locale,'ba')} projectId={projectId} writable={canWrite}/><QueueWorkspace projectId={projectId} scopeGeneration={scopeGeneration} locale={locale} domain="ba" canWrite={canWrite}/></section>;
}
