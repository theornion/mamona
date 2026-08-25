param(
    [Parameter(Mandatory = $true)][string]$InputPath,
    [Parameter(Mandatory = $true)][string]$OutputPath,
    [Parameter(Mandatory = $true)][int64]$MaxBytes
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing

$source = [System.Drawing.Image]::FromFile($InputPath)
try {
    $scale = 1.0
    $quality = 88L
    for ($attempt = 0; $attempt -lt 8; $attempt++) {
        $width = [Math]::Max(1, [int][Math]::Round($source.Width * $scale))
        $height = [Math]::Max(1, [int][Math]::Round($source.Height * $scale))
        $canvas = New-Object System.Drawing.Bitmap $width, $height
        try {
            $canvas.SetResolution(96, 96)
            $graphics = [System.Drawing.Graphics]::FromImage($canvas)
            try {
                $graphics.Clear([System.Drawing.Color]::White)
                $graphics.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
                $graphics.DrawImage($source, 0, 0, $width, $height)
            } finally {
                $graphics.Dispose()
            }
            $codec = [System.Drawing.Imaging.ImageCodecInfo]::GetImageEncoders() |
                Where-Object { $_.MimeType -eq 'image/jpeg' } |
                Select-Object -First 1
            $parameters = New-Object System.Drawing.Imaging.EncoderParameters 1
            $parameters.Param[0] = New-Object System.Drawing.Imaging.EncoderParameter ([System.Drawing.Imaging.Encoder]::Quality), $quality
            $canvas.Save($OutputPath, $codec, $parameters)
            $parameters.Dispose()
        } finally {
            $canvas.Dispose()
        }
        if ((Get-Item -LiteralPath $OutputPath).Length -le $MaxBytes) { exit 0 }
        $scale *= 0.72
        $quality = [Math]::Max(55L, $quality - 7L)
    }
    throw 'Prepared Vision copy still exceeds the payload limit.'
} finally {
    $source.Dispose()
}
