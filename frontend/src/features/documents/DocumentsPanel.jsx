import { useRef } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Upload, Trash2, FileText, Download } from 'lucide-react'
import Button from '../../components/ui/Button'
import { fetchDocuments, uploadDocument, deleteDocument, documentDownloadUrl } from './documentsApi'

export default function DocumentsPanel({ documentableType, documentableId }) {
  const fileInputRef = useRef(null)
  const queryClient = useQueryClient()
  const queryKey = ['documents', documentableType, documentableId]

  const { data: documents } = useQuery({
    queryKey,
    queryFn: () => fetchDocuments(documentableType, documentableId),
  })

  const uploadMutation = useMutation({
    mutationFn: (fichier) => uploadDocument({ documentableType, documentableId, fichier }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey }),
  })

  const deleteMutation = useMutation({
    mutationFn: deleteDocument,
    onSuccess: () => queryClient.invalidateQueries({ queryKey }),
  })

  const handleFileChange = (e) => {
    const fichier = e.target.files?.[0]
    if (fichier) uploadMutation.mutate(fichier)
    e.target.value = ''
  }

  return (
    <div className="rounded-xl border border-slate-200 bg-white p-6">
      <div className="mb-4 flex items-center justify-between">
        <h2 className="text-lg font-semibold text-slate-800">Documents</h2>
        <Button variant="outline" onClick={() => fileInputRef.current?.click()} disabled={uploadMutation.isPending}>
          <span className="flex items-center gap-2">
            <Upload size={16} /> {uploadMutation.isPending ? 'Envoi…' : 'Ajouter un document'}
          </span>
        </Button>
        <input ref={fileInputRef} type="file" className="hidden" onChange={handleFileChange} />
      </div>

      {documents?.length ? (
        <ul className="divide-y divide-slate-100">
          {documents.map((doc) => (
            <li key={doc.id} className="flex items-center justify-between py-2 text-sm">
              <span className="flex items-center gap-2 text-slate-700">
                <FileText size={16} className="text-slate-400" />
                {doc.nom}
              </span>
              <div className="flex items-center gap-2">
                <a
                  href={documentDownloadUrl(doc.id)}
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
            </li>
          ))}
        </ul>
      ) : (
        <p className="text-sm text-slate-400">Aucun document pour l'instant.</p>
      )}
    </div>
  )
}
