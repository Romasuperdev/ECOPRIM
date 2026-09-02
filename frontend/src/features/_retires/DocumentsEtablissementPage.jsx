import { useRef } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Upload, Trash2, FileText, Download } from 'lucide-react'
import Button from '../../components/ui/Button'
import {
  fetchDocumentsEtablissement,
  uploadDocumentEtablissement,
  deleteDocumentEtablissement,
  documentEtablissementDownloadUrl,
} from './documentsApi'

export default function DocumentsEtablissementPage() {
  const fileInputRef = useRef(null)
  const queryClient = useQueryClient()

  const { data: documents, isLoading } = useQuery({
    queryKey: ['documents-etablissement'],
    queryFn: fetchDocumentsEtablissement,
  })

  const uploadMutation = useMutation({
    mutationFn: (fichier) => uploadDocumentEtablissement({ fichier }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['documents-etablissement'] }),
  })

  const deleteMutation = useMutation({
    mutationFn: deleteDocumentEtablissement,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['documents-etablissement'] }),
  })

  const handleFileChange = (e) => {
    const fichier = e.target.files?.[0]
    if (fichier) uploadMutation.mutate(fichier)
    e.target.value = ''
  }

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-800">Documents établissement</h1>
        <Button onClick={() => fileInputRef.current?.click()} disabled={uploadMutation.isPending}>
          <span className="flex items-center gap-2">
            <Upload size={16} /> {uploadMutation.isPending ? 'Envoi…' : 'Ajouter un document'}
          </span>
        </Button>
        <input ref={fileInputRef} type="file" className="hidden" onChange={handleFileChange} />
      </div>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Nom</th>
              <th className="px-4 py-3 font-medium">Type</th>
              <th className="px-4 py-3 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && (
              <tr>
                <td colSpan={3} className="px-4 py-6 text-center text-slate-400">
                  Chargement…
                </td>
              </tr>
            )}
            {!isLoading && documents?.length === 0 && (
              <tr>
                <td colSpan={3} className="px-4 py-6 text-center text-slate-400">
                  Aucun document.
                </td>
              </tr>
            )}
            {documents?.map((doc) => (
              <tr key={doc.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 font-medium text-slate-800">
                  <span className="flex items-center gap-2">
                    <FileText size={16} className="text-slate-400" />
                    {doc.nom}
                  </span>
                </td>
                <td className="px-4 py-3 text-slate-600">{doc.type_mime ?? '—'}</td>
                <td className="px-4 py-3">
                  <div className="flex justify-end gap-2">
                    <a
                      href={documentEtablissementDownloadUrl(doc.id)}
                      className="rounded p-1.5 text-slate-500 hover:bg-slate-100 hover:text-primary-700"
                    >
                      <Download size={16} />
                    </a>
                    <button
                      onClick={() => deleteMutation.mutate(doc.id)}
                      className="rounded p-1.5 text-slate-500 hover:bg-red-50 hover:text-red-600"
                    >
                      <Trash2 size={16} />
                    </button>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
