import { useQuery } from '@tanstack/react-query'
import { fetchJournalActivite } from './adminApi'

const ACTION_LABELS = {
  create: 'Création',
  update: 'Modification',
  delete: 'Suppression',
  deactivate: 'Désactivation',
  activate: 'Activation',
  reopen: 'Réouverture',
}

export default function JournalActivitePage() {
  const { data, isLoading } = useQuery({ queryKey: ['admin', 'journal-activite', 1], queryFn: () => fetchJournalActivite(1) })

  return (
    <div>
      <h1 className="mb-1 text-2xl font-bold text-slate-800">Journal d'activité</h1>
      <p className="mb-6 text-sm text-slate-500">Lecture seule — immuable, y compris pour le Super Admin.</p>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Date</th>
              <th className="px-4 py-3 font-medium">Utilisateur</th>
              <th className="px-4 py-3 font-medium">Action</th>
              <th className="px-4 py-3 font-medium">Module</th>
              <th className="px-4 py-3 font-medium">Objet</th>
              <th className="px-4 py-3 font-medium">IP</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && (
              <tr>
                <td colSpan={6} className="px-4 py-6 text-center text-slate-400">
                  Chargement…
                </td>
              </tr>
            )}
            {!isLoading && !data?.data?.length && (
              <tr>
                <td colSpan={6} className="px-4 py-6 text-center text-slate-400">
                  Aucune activité enregistrée
                </td>
              </tr>
            )}
            {data?.data?.map((entry) => (
              <tr key={entry.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 text-slate-500">{new Date(entry.created_at).toLocaleString('fr-FR')}</td>
                <td className="px-4 py-3 text-slate-600">{entry.user?.name ?? '—'}</td>
                <td className="px-4 py-3 font-medium text-slate-800">{ACTION_LABELS[entry.action] ?? entry.action}</td>
                <td className="px-4 py-3 text-slate-600">{entry.module}</td>
                <td className="px-4 py-3 text-slate-500">{entry.objet_type ? `${entry.objet_type} #${entry.objet_id}` : '—'}</td>
                <td className="px-4 py-3 text-slate-400">{entry.ip ?? '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
