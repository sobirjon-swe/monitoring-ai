Add-Type -TypeDefinition @'
using System;
using System.IO;
using System.Text;
using System.Threading.Tasks;
public static class F02BoundedDrain {
 public static async Task<string> Read(TextReader reader, int limit) {
  if(limit < 1) throw new ArgumentException("limit");
  var output=new StringBuilder(); char[] chunk=new char[256]; int n;
  while((n=await reader.ReadAsync(chunk,0,chunk.Length)) != 0) { if(output.Length+n > limit) throw new InvalidDataException("output limit exceeded"); output.Append(chunk,0,n); }
  return output.ToString();
 }
}
'@
